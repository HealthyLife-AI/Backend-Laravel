-- Legacy rows for verifying the phase-1 migrations on MySQL (see DEPLOYMENT.md, "Verifying the phase-1 migrations on MySQL").
-- Load it into a SCRATCH database only, at the schema state just before those five migrations. Never into a real database.

-- Two nutritionists, patients with a gap in their codes (PT-102, PT-104..106 "were deleted"),
-- and one legacy code that is not PT-<number>.
INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES
  (901, 'Verify Nutritionist A', 'va@verify.test', 'x', NOW(), NOW()),
  (902, 'Verify Nutritionist B', 'vb@verify.test', 'x', NOW(), NOW());
INSERT INTO users (id, name, email, password, nutritionist_id, created_at, updated_at) VALUES
  (911, 'Patient 1', 'p1@verify.test', 'x', 901, NOW(), NOW()),
  (912, 'Patient 2', 'p2@verify.test', 'x', 901, NOW(), NOW()),
  (913, 'Patient 3', 'p3@verify.test', 'x', 901, NOW(), NOW()),
  (914, 'Patient 4', 'p4@verify.test', 'x', 901, NOW(), NOW());
INSERT INTO subscribers (id, user_id, nutritionist_id, code, goal, status, created_at, updated_at) VALUES
  (1001, 911, 901, 'PT-101', 'weight_loss', 'active', NOW(), NOW()),
  (1002, 912, 901, 'PT-107', 'weight_loss', 'active', NOW(), NOW()),
  (1003, 913, 901, 'PT-103', 'weight_loss', 'active', NOW(), NOW()),
  (1004, 914, 901, 'OLD-9',  'weight_loss', 'active', NOW(), NOW());

-- One plan with a breakfast and a dinner meal, and three logs: two on-plan, one off-plan.
INSERT INTO foods (id, name_en, source, status, calories_per_100g, protein_g_per_100g, carbs_g_per_100g, fat_g_per_100g, created_at, updated_at)
  VALUES (4001, 'Verify food', 'admin', 'approved', 100, 10, 10, 5, NOW(), NOW());
INSERT INTO meal_plans (id, subscriber_id, created_by, status, created_at, updated_at) VALUES (2001, 1001, 901, 'active', NOW(), NOW());
INSERT INTO meals (id, meal_plan_id, name, sort_order, created_at, updated_at) VALUES
  (3001, 2001, 'dinner', 1, NOW(), NOW()), (3002, 2001, 'breakfast', 0, NOW(), NOW());
INSERT INTO meal_items (id, meal_id, food_id, quantity_grams, sort_order, created_at, updated_at) VALUES
  (5001, 3001, 4001, 100, 0, NOW(), NOW()), (5002, 3002, 4001, 100, 0, NOW(), NOW());
INSERT INTO meal_logs (id, subscriber_id, food_id, meal_item_id, quantity_grams, logged_at, created_at, updated_at) VALUES
  (6001, 1001, 4001, 5001, 100, NOW(), NOW(), NOW()),
  (6002, 1001, 4001, 5002, 100, NOW(), NOW(), NOW()),
  (6003, 1001, 4001, NULL, 100, NOW(), NOW(), NOW());
