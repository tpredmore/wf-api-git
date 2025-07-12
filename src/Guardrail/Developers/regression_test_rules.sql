USE wildfire_configuration;

TRUNCATE TABLE wildfire_configuration.guardrail;

INSERT INTO guardrail (
    type,
    area,
    sequence,
    target,
    operator_id,
    criteria,
    sub_rule,
    on_fail,
    on_pass,
    pass,
    fail,
    warn
) VALUES
-- 1. Field existence check
(
    'ACTION', 'TEST', 1, '["test.field_A"]', 1,
    NULL, NULL, 'RESTRICT', 'CONTINUE',
    'Field A exists.', 'Field A is missing!', 'Ensure Field A is provided.'
),
-- 2. Boolean must be true
(
    'ACTION', 'TEST', 2, '["test.boolean_A"]', 2,
    NULL, NULL, 'RESTRICT', 'CONTINUE',
    'Boolean A is TRUE.', 'Boolean A is FALSE!', 'Boolean A must be TRUE before proceeding.'
),
-- 3. Boolean must be false
(
    'ACTION', 'TEST', 3, '["test.boolean_B"]', 3,
    NULL, NULL, 'RESTRICT', 'CONTINUE',
    'Boolean B is FALSE.', 'Boolean B is TRUE!', 'Boolean B must be FALSE before proceeding.'
),
-- 4. Regex validation
(
    'ACTION', 'TEST', 4, '["test.field_A"]', 4,
    '"^[A-Za-z0-9]+$"', NULL, 'WARN', 'CONTINUE',
    'Field A format is valid.', 'Field A format is invalid!', 'Field A must be alphanumeric.'
),
-- 5. Ensure a number is greater than a given threshold
(
    'ACTION', 'TEST', 5, '["test.number_A"]', 5,
    '100', NULL, 'RESTRICT', 'CONTINUE',
    'Number A is greater than 100.', 'Number A is not greater than 100!', 'Number A must be above 100.'
),
-- 6. Ensure a number is greater than or equal to a given threshold
(
    'ACTION', 'TEST', 6, '["test.number_B"]', 6,
    '100', NULL, 'RESTRICT', 'CONTINUE',
    'Number B is at least 100.', 'Number B is too low!', 'Number B must be at least 100.'
),
-- 7. Ensure a number is less than a given threshold
(
    'ACTION', 'TEST', 7, '["test.number_C"]', 7,
    '50000', NULL, 'WARN', 'CONTINUE',
    'Number C is below the limit.', 'Number C exceeds the limit!', 'Ensure Number C does not exceed 50,000.'
),
-- 8. Ensure a number is less than or equal to a given threshold
(
    'ACTION', 'TEST', 8, '["test.number_D"]', 8,
    '75000', NULL, 'WARN', 'CONTINUE',
    'Number D is within the allowed range.', 'Number D is too high!', 'Ensure Number D is at most 75,000.'
),
-- 9. Ensure a number is exactly equal to a given value
(
    'ACTION', 'TEST', 9, '["test.number_E"]', 9,
    '100', NULL, 'RESTRICT', 'CONTINUE',
    'Number E is exactly 100.', 'Number E is not 100!', 'Ensure Number E is exactly 100.'
),
-- 10. Ensure a number is NOT equal to a given value
(
    'ACTION', 'TEST', 10, '["test.number_F"]', 10,
    '101', NULL, 'RESTRICT', 'CONTINUE',
    'Number F is not 101.', 'Number F is exactly 101!', 'Ensure Number F is different from 101.'
),
-- 11. Status validation (string equality)
(
    'ACTION', 'TEST', 11, '["test.status_A"]', 11,
    'ACTIVE', NULL, 'RESTRICT', 'CONTINUE',
    'Status A is ACTIVE.', 'Status A is not ACTIVE!', 'Ensure Status A is correctly set.'
),
-- 12. Ensure a status is NOT equal to a given value
(
    'ACTION', 'TEST', 12, '["test.status_B"]', 12,
    'INACTIVE', NULL, 'RESTRICT', 'CONTINUE',
    'Status B is not INACTIVE.', 'Status B is INACTIVE!', 'Ensure Status B is not set to INACTIVE.'
),
-- 13. Ensure a value is in a predefined set
(
    'ACTION', 'TEST', 13, '["test.status_C"]', 13,
    '["ACTIVE","PENDING", "INACTIVE"]', NULL, 'RESTRICT', 'CONTINUE',
    'Status C is in the allowed set.', 'Status C is not in the allowed set!', 'Ensure Status C is either ACTIVE or PENDING.'
),
-- 14. Between operator test (Numeric comparison)
(
    'ACTION', 'TEST', 14, '["test.number_G"]', 14,
    '{"from":50,"to":200}', NULL, 'WARN', 'CONTINUE',
    'Number G is within range.', 'Number G is out of range!', 'Check that Number G is between 50 and 200.'
),
-- 15. Date tolerance check
(
    'ACTION', 'TEST', 15, '["test.date_A"]', 1,
    NULL,
    '{"depends":["test.date_A","test.date_B"],"operator_name":"date_tolerance","criteria":{"min":10,"max":30},"on_fail":"WARN"}',
    'RESTRICT', 'CONTINUE', 'Date A exists.', 'Date A is missing!', 'Ensure Date A is provided.'
),
-- 16. Date tolerance (second data source)
(
    'ACTION', 'TEST', 15, '["test.date_A"]', 1,
    NULL,
    '{"depends":["test.date_A","test.date_B"],"operator_name":"date_tolerance","criteria":["test2.tolerance_max"],"on_fail":"RESTRICT","fail":"OUT of date_tolerance"}',
    'RESTRICT', 'CONTINUE', 'Date A exists.', 'Date A is missing!', 'Ensure Date A is provided.'
);

