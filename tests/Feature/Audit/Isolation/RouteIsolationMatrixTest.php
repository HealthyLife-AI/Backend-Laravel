<?php

namespace Tests\Feature\Audit\Isolation;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Audit I1: GENERATED isolation matrix. Every api/v1 route (read from the router at
 * data-provider time, so a new route is covered automatically) x every hostile actor.
 * Route parameters are filled with victim (clinic A / patient A1) ids. The rule:
 *   - never 2xx on a route whose parameters name a victim record;
 *   - never a body containing IsolationWorld::MARK (any status);
 *   - guests get 401, deleted-account tokens 401, archived-patient tokens no 2xx;
 *   - patients never 2xx on a nutritionist/admin route, nutritionists never on an admin route.
 * An "owner" control row proves the victim ids are real (owner GET returns 2xx), so the matrix
 * can't pass because the fixtures point nowhere.
 *
 * Designed to become a permanent CI test: KNOWN_LEAKS lists the open findings; empty it as they are fixed.
 */
class RouteIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Routes reachable without a token by design. */
    private const PUBLIC_ROUTES = [
        'api.auth.register', 'api.auth.login', 'api.auth.refresh', 'api.auth.logout', 'api.auth.google',
        'api.auth.forgot-password', 'api.auth.reset-password', 'api.invites.activate',
    ];

    /** Non-`me/` routes a patient may legitimately call. */
    private const PATIENT_SHARED = ['api.auth.me', 'api.me.password.update', 'api.foods.search'];

    private const ACTORS = ['guest', 'nutB', 'nutB_ownParent', 'A2', 'B1', 'A3_archived', 'A4_deleted', 'admin', 'owner'];

    private IsolationWorld $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = IsolationWorld::build();
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function matrix(): iterable
    {
        foreach (self::apiRoutes() as [$method, $uri, $name, $mw]) {
            foreach (self::ACTORS as $actor) {
                if ($actor === 'nutB_ownParent' && ! (str_contains($uri, '{subscriber}') && preg_match_all('/\{/', $uri) > 1)) {
                    continue;
                }
                yield "{$method} {$uri} as {$actor}" => [$method, $uri, $name, $actor];
            }
        }
    }

    /** @return list<array{string, string, string, string}> */
    private static function apiRoutes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        // Bootstrapping installs Laravel's error/exception handlers; drop them again so PHPUnit doesn't flag every case as risky.
        restore_error_handler();
        restore_exception_handler();
        $cache = [];
        /** @var Route $route */
        foreach ($app['router']->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $cache[] = [$method, $route->uri(), (string) $route->getName(), implode(',', $route->gatherMiddleware())];
        }
        $app->flush();

        return $cache;
    }

    #[DataProvider('matrix')]
    public function test_route_is_isolated(string $method, string $uri, string $name, string $actor): void
    {
        $route = app('router')->getRoutes()->getByName($name);
        $mw = implode(',', $route->gatherMiddleware());
        $isPublic = in_array($name, self::PUBLIC_ROUTES, true);
        $isAdminRoute = (bool) preg_match('/role:admin|permission:(users\.manage|foods\.manage|foods\.approve)/', $mw);
        $isMeRoute = self::isPatientRoute($mw, $name);
        $hasVictimParam = (bool) preg_match('/\{(?!country)/', $uri);

        if ($actor === 'owner') {
            $this->ownerControl($method, $uri, $name, $isPublic, $isAdminRoute, $isMeRoute);

            return;
        }

        $url = $this->fill($uri, $name, $actor === 'nutB_ownParent' ? 'B1' : null);
        $token = match ($actor) {
            'guest' => null,
            'nutB', 'nutB_ownParent' => $this->w->tokens['nutB'],
            'A3_archived' => $this->w->tokens['A3'],
            'A4_deleted' => $this->w->tokens['A4'],
            default => $this->w->tokens[$actor],
        };
        $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
        $res = $method === 'GET' ? $this->getJson($url.self::QUERY, $headers) : $this->json($method, $url, $this->body($name), $headers);
        $status = $res->getStatusCode();
        $ctx = "{$actor} {$method} {$url} -> {$status}: ".substr((string) $res->getContent(), 0, 300);
        if ($log = getenv('AUDIT_MATRIX_LOG')) {
            file_put_contents($log, "{$status}\t{$actor}\t{$method}\t{$name}\t".substr(str_replace(["\n", "\t"], ' ', (string) $res->getContent()), 0, 160)."\n", FILE_APPEND);
        }

        if ($actor === 'admin' && $isAdminRoute) {
            // Moderating every clinic's submitted foods / nutritionists is the admin's job.
            $this->assertNotSame(500, $status, "500 {$ctx}");

            return;
        }
        $this->assertStringNotContainsString(IsolationWorld::MARK, (string) $res->getContent(), "LEAK (victim data in body) {$ctx}");
        $this->assertNotSame(500, $status, "500 {$ctx}");

        if ($isPublic) {
            return;
        }
        if (in_array($actor, ['guest', 'A4_deleted'], true)) {
            $this->assertSame(401, $status, "expected 401 {$ctx}");

            return;
        }
        $ok = $status >= 200 && $status < 300;
        if ($actor === 'A3_archived') {
            $this->assertFalse($ok, "archived patient's old token still works: {$ctx}");

            return;
        }
        if ($hasVictimParam) {
            $this->assertFalse($ok, "2xx on a route naming victim records: {$ctx}");
        }
        if ($isAdminRoute && $actor !== 'admin') {
            $this->assertContains($status, [401, 403], "non-admin reached admin route: {$ctx}");
        }
        if (in_array($actor, ['A2', 'B1'], true) && ! $isMeRoute) {
            $this->assertFalse($ok, "patient reached nutritionist/admin route: {$ctx}");
        }
    }

    private static function isPatientRoute(string $mw, string $name): bool
    {
        return (bool) preg_match('/role:client|permission:(plans\.view\.own|logs\.manage\.own)/', $mw) || in_array($name, self::PATIENT_SHARED, true);
    }

    /** Query string for GET routes: a search for the victim mark, plus required filters. */
    private const QUERY = '?q='.IsolationWorld::MARK.'&type=follow_up';

    private function ownerControl(string $method, string $uri, string $name, bool $isPublic, bool $isAdminRoute, bool $isMeRoute): void
    {
        if ($method !== 'GET' || $isPublic || $isAdminRoute || in_array($name, ['api.system.ai-status', 'api.system.scheduler-status', 'api.system.fcm-status'], true)) {
            $this->assertTrue(true);

            return;
        }
        $token = $isMeRoute ? $this->w->tokens['A1'] : $this->w->tokens['nutA'];
        $res = $this->getJson($this->fill($uri, $name, null).self::QUERY, ['Authorization' => "Bearer {$token}"]);
        $this->assertTrue($res->isSuccessful(), "fixture control: owner GET {$uri} -> {$res->getStatusCode()} ".substr((string) $res->getContent(), 0, 300));
    }

    private function fill(string $uri, string $name, ?string $ownParent): string
    {
        $ids = $this->w->ids;
        $byName = [
            'api.me.meal-logs.update' => 'mealLog', 'api.me.meal-logs.destroy' => 'mealLog',
            'api.me.measurements.destroy' => 'reading',
            'api.me.reports.file' => 'report', 'api.me.reports.destroy' => 'report',
            'api.me.posts.show' => 'post', 'api.me.posts.read' => 'post',
            'api.me.checkins.submit' => 'openCheckin',
            'api.notices.destroy' => 'notice',
            'api.me.notifications.read' => 'notification',
        ];

        return '/'.preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($ids, $byName, $name, $ownParent) {
            return (string) match ($m[1]) {
                'subscriber' => $ownParent ? $this->w->subs[$ownParent]->id : $this->w->subs['A1']->id,
                'id' => $ids[$byName[$name] ?? throw new \LogicException("no id mapping for {$name}")],
                'mealPlan' => str_starts_with($name, 'api.meal-plan-templates') ? $ids['template'] : $ids['mealPlan'],
                'nutritionist' => $this->w->users['nutA']->id,
                'country' => 'PS',
                'date' => $ids['wellbeingDate'],
                'token' => 'not-a-real-token',
                'quickRequest' => $ids['quickRequest'],
                'food' => $ids['food'],
                default => $ids[$m[1]] ?? throw new \LogicException("no fixture for {{$m[1]}} in {$name}"),
            };
        }, $uri);
    }

    /** A permissive body that satisfies most write validators, so 4xx comes from authorization, not validation. */
    private function body(string $name): array
    {
        $food = $this->w->ids['sharedFood'];

        return [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food, 'quantity_grams' => 100]]]],
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'moderate',
            'recorded_at' => now()->toDateString(), 'title' => 'x', 'body' => 'x', 'note' => 'x', 'reason' => 'occasion',
            'name' => 'x', 'group' => 'other', 'other_text' => 'x', 'class' => 'avoid', 'code' => 'other',
            'rating' => 'on_track', 'decision' => 'approve', 'reviewed' => true, 'food_id' => $food, 'quantity_grams' => 100,
            'meal_type' => 'breakfast', 'logged_at' => now()->toIso8601String(), 'mood' => 3, 'interval_days' => 14,
            'visible' => true, 'audience' => 'all', 'goal_type' => 'weight_loss', 'enabled' => true, 'response' => 'x',
            'cost_tier' => 'low', 'country' => 'PS', 'confirm' => true, 'password' => 'x', 'current_password' => 'password', 'starts_at' => now()->addDays(5)->setTime(11, 0)->toIso8601String(),
        ];
    }
}
