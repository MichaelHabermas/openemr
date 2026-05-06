-- AgentForge demo data.
-- Idempotent for fake patients pid=900001-900006 and 900101-900107. This script never drops tables.

SET @demo_pid := 900001;
SET @demo_pubpid := 'AF-DEMO-900001';
SET @poly_pid := 900002;
SET @poly_pubpid := 'AF-DEMO-900002';
SET @sparse_pid := 900003;
SET @sparse_pubpid := 'AF-DEMO-900003';
SET @empty_pid := 900004;
SET @empty_pubpid := 'AF-DEMO-900004';
SET @partial_pid := 900005;
SET @partial_pubpid := 'AF-DEMO-900005';
SET @layout_pid := 900006;
SET @layout_pubpid := 'AF-DEMO-900006';
SET @chen_pid := 900101;
SET @chen_pubpid := 'BHS-2847163';
SET @h7_whitaker_pid := 900102;
SET @h7_whitaker_pubpid := 'NMM-9912448';
SET @h7_reyes_pid := 900103;
SET @h7_reyes_pubpid := 'ATX-5503291';
SET @h7_kowalski_pid := 900104;
SET @h7_kowalski_pubpid := 'NWM-7724501';
SET @h7_patel_pid := 900105;
SET @h7_patel_pubpid := 'EMR-4413089';
SET @h7_johnson_pid := 900106;
SET @h7_johnson_pubpid := 'HFH-8866213';
SET @h7_nguyen_pid := 900107;
SET @h7_nguyen_pubpid := 'UWM-3320175';
SET @demo_user := 'admin';
SET @demo_group := 'Default';
SET @encounter_id := 900415;
SET @poly_encounter_id := 900516;
SET @sparse_encounter_id := 900617;
SET @demo_enc_annual := 900418;
SET @demo_enc_diabetes_fu := 900419;
SET @demo_enc_medrecon := 900420;
SET @demo_enc_tele := 900421;
SET @poly_enc_annual := 900518;
SET @poly_enc_tele := 900519;
SET @demo_appt_eid := 90004500;
SET @unrelated_user_id := 900090;
SET @unrelated_aro_id := 900090;
SET @unrelated_username := 'af_demo_unrelated';
SET @ct_nurse_user_id := 900201;
SET @ct_nurse_username := 'af_demo_ct_nurse';
SET @ct_spec_user_id := 900202;
SET @ct_spec_username := 'af_demo_ct_specialist';
SET @admin_acl_group_id := 11;

START TRANSACTION;

DELETE FROM gacl_groups_aro_map WHERE aro_id IN (@unrelated_aro_id, @ct_nurse_user_id, @ct_spec_user_id);
DELETE FROM gacl_aro
WHERE id IN (@unrelated_aro_id, @ct_nurse_user_id, @ct_spec_user_id)
    OR (section_value = 'users' AND value IN (@unrelated_username, @ct_nurse_username, @ct_spec_username));
DELETE FROM groups WHERE user IN (@unrelated_username, @ct_nurse_username, @ct_spec_username);
DELETE FROM users_secure
WHERE id IN (@unrelated_user_id, @ct_nurse_user_id, @ct_spec_user_id)
    OR username IN (@unrelated_username, @ct_nurse_username, @ct_spec_username);
DELETE FROM users
WHERE id IN (@unrelated_user_id, @ct_nurse_user_id, @ct_spec_user_id)
    OR username IN (@unrelated_username, @ct_nurse_username, @ct_spec_username);

DELETE ctm
FROM care_team_member ctm
INNER JOIN care_teams ct ON ct.id = ctm.care_team_id
WHERE ct.pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE FROM care_teams WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE FROM insurance_data WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE FROM openemr_postcalendar_events
WHERE pc_hometext LIKE 'agentforge-demo-appt-%'
    OR pc_eid = @demo_appt_eid;

DELETE pr
FROM procedure_result pr
INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE rep
FROM procedure_report rep
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE poc
FROM procedure_order_code poc
INNER JOIN procedure_order po ON po.procedure_order_id = poc.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);

DELETE FROM procedure_order WHERE patient_id IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM forms WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM form_vitals WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM form_clinical_notes WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM form_encounter WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM prescriptions WHERE patient_id IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE lm
FROM lists_medication lm
LEFT JOIN lists l ON l.id = lm.list_id
WHERE lm.id = 90000203
    AND (l.pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid) OR l.id IS NULL);
DELETE lm
FROM lists_medication lm
INNER JOIN lists l ON l.id = lm.list_id
WHERE l.pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM lists WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid);
DELETE FROM patient_data
WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid, @chen_pid, @h7_whitaker_pid, @h7_reyes_pid, @h7_kowalski_pid, @h7_patel_pid, @h7_johnson_pid, @h7_nguyen_pid, @empty_pid, @partial_pid, @layout_pid)
    OR pubpid IN (
        @demo_pubpid,
        @poly_pubpid,
        @sparse_pubpid,
        @chen_pubpid,
        @h7_whitaker_pubpid,
        @h7_reyes_pubpid,
        @h7_kowalski_pubpid,
        @h7_patel_pubpid,
        @h7_johnson_pubpid,
        @h7_nguyen_pubpid,
        @empty_pubpid,
        @partial_pubpid,
        @layout_pubpid
    );

INSERT INTO patient_data (
    id,
    uuid,
    fname,
    lname,
    DOB,
    sex,
    date,
    pubpid,
    pid,
    providerID,
    status,
    street,
    city,
    state,
    postal_code,
    phone_cell,
    email,
    birth_fname,
    birth_lname,
    gender_identity
) VALUES (
    @demo_pid,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000001', '-', '')),
    'Alex',
    'Testpatient',
    '1976-04-12',
    'Female',
    '2026-04-15 08:00:00',
    @demo_pubpid,
    @demo_pid,
    1,
    'active',
    '100 Demo Street',
    'Faketown',
    'NY',
    '10001',
    '555-0100',
    'alex.testpatient@example.invalid',
    'Alexandra',
    'Birthsurname',
    '446141000124107'
);

INSERT INTO patient_data (
    id,
    uuid,
    fname,
    mname,
    lname,
    DOB,
    sex,
    date,
    pubpid,
    pid,
    providerID,
    status,
    street,
    city,
    state,
    postal_code,
    phone_cell,
    email
) VALUES (
    @chen_pid,
    UNHEX(REPLACE('90010100-0000-4000-8000-000000000001', '-', '')),
    'Margaret',
    'L',
    'Chen',
    '1968-03-12',
    'Female',
    '2026-05-06 08:00:00',
    @chen_pubpid,
    @chen_pid,
    1,
    'active',
    '2418 CHANNING WAY',
    'BERKELEY',
    'CA',
    '94704',
    '510-555-0142',
    'margaret.chen@example.invalid'
);

INSERT INTO patient_data (
    id,
    uuid,
    fname,
    mname,
    lname,
    DOB,
    sex,
    date,
    pubpid,
    pid,
    providerID,
    status,
    street,
    city,
    state,
    postal_code,
    phone_cell,
    email
) VALUES
(
    @h7_whitaker_pid,
    UNHEX(REPLACE('90010200-0000-4000-8000-000000000001', '-', '')),
    'James',
    'R',
    'Whitaker',
    '1958-11-22',
    'Male',
    '2026-05-06 08:00:00',
    @h7_whitaker_pubpid,
    @h7_whitaker_pid,
    1,
    'active',
    '4127 COMANCHE RD NE',
    'ALBUQUERQUE',
    'NM',
    '87110',
    '505-555-0188',
    'james.whitaker@example.invalid'
),
(
    @h7_reyes_pid,
    UNHEX(REPLACE('90010300-0000-4000-8000-000000000001', '-', '')),
    'Sofia',
    'I',
    'Reyes',
    '1983-07-04',
    'Female',
    '2026-05-06 08:00:00',
    @h7_reyes_pubpid,
    @h7_reyes_pid,
    1,
    'active',
    '1809 E CESAR CHAVEZ ST',
    'AUSTIN',
    'TX',
    '78702',
    '512-555-0167',
    'sofia.reyes@example.invalid'
),
(
    @h7_kowalski_pid,
    UNHEX(REPLACE('90010400-0000-4000-8000-000000000001', '-', '')),
    'Robert',
    'J',
    'Kowalski',
    '1971-09-30',
    'Male',
    '2026-05-06 08:00:00',
    @h7_kowalski_pubpid,
    @h7_kowalski_pid,
    1,
    'active',
    '3344 W BELMONT AVE',
    'CHICAGO',
    'IL',
    '60618',
    '773-555-0124',
    'robert.kowalski@example.invalid'
),
(
    @h7_patel_pid,
    UNHEX(REPLACE('90010500-0000-4000-8000-000000000001', '-', '')),
    'Aisha',
    'K',
    'Patel',
    '1991-06-15',
    'Female',
    '2026-05-06 08:00:00',
    @h7_patel_pubpid,
    @h7_patel_pid,
    1,
    'active',
    '1186 PEACHTREE ST NE',
    'ATLANTA',
    'GA',
    '30309',
    '404-555-0173',
    'aisha.patel@example.invalid'
),
(
    @h7_johnson_pid,
    UNHEX(REPLACE('90010600-0000-4000-8000-000000000001', '-', '')),
    'Marcus',
    'T',
    'Johnson',
    '1954-02-08',
    'Male',
    '2026-05-06 08:00:00',
    @h7_johnson_pubpid,
    @h7_johnson_pid,
    1,
    'active',
    '8821 WOODWARD AVE',
    'DETROIT',
    'MI',
    '48202',
    '313-555-0156',
    'marcus.johnson@example.invalid'
),
(
    @h7_nguyen_pid,
    UNHEX(REPLACE('90010700-0000-4000-8000-000000000001', '-', '')),
    'Olivia',
    'T',
    'Nguyen',
    '1997-10-19',
    'Female',
    '2026-05-06 08:00:00',
    @h7_nguyen_pubpid,
    @h7_nguyen_pid,
    1,
    'active',
    '2104 NE 65TH ST',
    'SEATTLE',
    'WA',
    '98115',
    '206-555-0149',
    'olivia.nguyen@example.invalid'
);

INSERT INTO users (
    id,
    uuid,
    username,
    password,
    authorized,
    fname,
    lname,
    facility_id,
    active,
    calendar
)
SELECT
    @unrelated_user_id,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000901', '-', '')),
    @unrelated_username,
    password,
    1,
    'AgentForge',
    'Unrelated',
    facility_id,
    1,
    0
FROM users
WHERE username = @demo_user
LIMIT 1;

INSERT INTO users_secure (
    id,
    username,
    password,
    last_update_password
)
SELECT
    @unrelated_user_id,
    @unrelated_username,
    password,
    NOW()
FROM users_secure
WHERE username = @demo_user
LIMIT 1;

INSERT INTO groups (
    name,
    user
) VALUES (
    @demo_group,
    @unrelated_username
);

INSERT INTO gacl_aro (
    id,
    section_value,
    value,
    order_value,
    name,
    hidden
) VALUES (
    @unrelated_aro_id,
    'users',
    @unrelated_username,
    @unrelated_aro_id,
    'AgentForge Unrelated',
    0
);

INSERT INTO gacl_groups_aro_map (
    group_id,
    aro_id
) VALUES (
    @admin_acl_group_id,
    @unrelated_aro_id
);

INSERT INTO users (
    id,
    uuid,
    username,
    password,
    authorized,
    fname,
    lname,
    facility_id,
    active,
    calendar
)
SELECT
    @ct_nurse_user_id,
    UNHEX(REPLACE('90020100-0000-4000-8000-000000000001', '-', '')),
    @ct_nurse_username,
    password,
    1,
    'Casey',
    'Nursepractitioner',
    facility_id,
    1,
    0
FROM users
WHERE username = @demo_user
LIMIT 1;

INSERT INTO users_secure (
    id,
    username,
    password,
    last_update_password
)
SELECT
    @ct_nurse_user_id,
    @ct_nurse_username,
    password,
    NOW()
FROM users_secure
WHERE username = @demo_user
LIMIT 1;

INSERT INTO groups (
    name,
    user
) VALUES (
    @demo_group,
    @ct_nurse_username
);

INSERT INTO gacl_aro (
    id,
    section_value,
    value,
    order_value,
    name,
    hidden
) VALUES (
    @ct_nurse_user_id,
    'users',
    @ct_nurse_username,
    @ct_nurse_user_id,
    'AgentForge CareTeam Nurse',
    0
);

INSERT INTO gacl_groups_aro_map (
    group_id,
    aro_id
) VALUES (
    @admin_acl_group_id,
    @ct_nurse_user_id
);

INSERT INTO users (
    id,
    uuid,
    username,
    password,
    authorized,
    fname,
    lname,
    facility_id,
    active,
    calendar
)
SELECT
    @ct_spec_user_id,
    UNHEX(REPLACE('90020200-0000-4000-8000-000000000001', '-', '')),
    @ct_spec_username,
    password,
    1,
    'Sam',
    'Specialistendo',
    facility_id,
    1,
    0
FROM users
WHERE username = @demo_user
LIMIT 1;

INSERT INTO users_secure (
    id,
    username,
    password,
    last_update_password
)
SELECT
    @ct_spec_user_id,
    @ct_spec_username,
    password,
    NOW()
FROM users_secure
WHERE username = @demo_user
LIMIT 1;

INSERT INTO groups (
    name,
    user
) VALUES (
    @demo_group,
    @ct_spec_username
);

INSERT INTO gacl_aro (
    id,
    section_value,
    value,
    order_value,
    name,
    hidden
) VALUES (
    @ct_spec_user_id,
    'users',
    @ct_spec_username,
    @ct_spec_user_id,
    'AgentForge CareTeam Specialist',
    0
);

INSERT INTO gacl_groups_aro_map (
    group_id,
    aro_id
) VALUES (
    @admin_acl_group_id,
    @ct_spec_user_id
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    reaction,
    severity_al,
    verification,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000701', '-', '')),
    '2026-04-01 09:00:00',
    'allergy',
    'Penicillin',
    '2026-04-01 00:00:00',
    'rash',
    'moderate',
    'confirmed',
    1,
    '',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-al-penicillin'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000702', '-', '')),
    '2025-11-20 09:00:00',
    'allergy',
    'Shellfish',
    '2025-11-20 00:00:00',
    'hives',
    'mild',
    'confirmed',
    1,
    '',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-al-shellfish'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000101', '-', '')),
    '2025-09-10 09:00:00',
    'medical_problem',
    'Type 2 diabetes mellitus',
    '2025-09-10 00:00:00',
    'ICD10:E11.9',
    1,
    'AgentForge demo active problem.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-prob-diabetes'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000102', '-', '')),
    '2024-02-18 09:00:00',
    'medical_problem',
    'Essential hypertension',
    '2024-02-18 00:00:00',
    'ICD10:I10',
    1,
    'AgentForge demo active problem.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-prob-htn'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000103', '-', '')),
    '2023-03-01 09:00:00',
    'medical_problem',
    'Hyperlipidemia',
    '2023-03-01 00:00:00',
    'ICD10:E78.5',
    0,
    'Resolved on lifestyle therapy; inactive for dashboard contrast.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-prob-lipid'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000203', '-', '')),
    '2026-02-10 09:00:00',
    'medication',
    'Aspirin 81 mg chewable (patient-reported OTC)',
    '2026-02-10 00:00:00',
    '',
    1,
    'Intentionally differs from prescription metformin/lisinopril rows for list-vs-Rx UI tests.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-l1-otc-asp'
);

INSERT INTO prescriptions (
    uuid,
    patient_id,
    filled_by_id,
    date_added,
    date_modified,
    provider_id,
    encounter,
    start_date,
    drug,
    dosage,
    quantity,
    route,
    refills,
    medication,
    note,
    active,
    datetime,
    user,
    txDate,
    usage_category_title,
    request_intent_title,
    drug_dosage_instructions,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000201', '-', '')),
    @demo_pid,
    1,
    '2026-03-15 10:00:00',
    '2026-03-15 10:00:00',
    1,
    @encounter_id,
    '2026-03-15',
    'Metformin ER 500 mg',
    '500 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge demo active medication.',
    1,
    '2026-03-15 10:00:00',
    @demo_user,
    '2026-03-15',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily with evening meal',
    'af-rx-metformin'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000202', '-', '')),
    @demo_pid,
    1,
    '2026-03-15 10:05:00',
    '2026-03-15 10:05:00',
    1,
    @encounter_id,
    '2026-03-15',
    'Lisinopril 10 mg',
    '10 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge demo active medication.',
    1,
    '2026-03-15 10:05:00',
    @demo_user,
    '2026-03-15',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily',
    'af-rx-lisinopril'
);

INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code,
    encounter_type_description,
    last_level_billed,
    last_level_closed,
    billing_note
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000301', '-', '')),
    '2026-04-15 09:30:00',
    'Follow-up for diabetes and blood pressure before a scheduled primary care visit.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @encounter_id,
    5,
    1,
    'AMB',
    'Primary care follow-up',
    1,
    0,
    'Primary insurance billed; patient copay collected.'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000311', '-', '')),
    '2026-01-08 09:00:00',
    'Annual wellness visit with preventive screening discussion.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @demo_enc_annual,
    5,
    1,
    'AMB',
    'Annual physical',
    2,
    1,
    'Secondary payer balance written off per policy.'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000312', '-', '')),
    '2026-02-20 10:15:00',
    'Diabetes follow-up: medication titration and nutrition counseling.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @demo_enc_diabetes_fu,
    5,
    1,
    'AMB',
    'Diabetes follow-up visit',
    1,
    0,
    'Claim pending primary adjudication.'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000313', '-', '')),
    '2026-03-05 11:00:00',
    'Medication reconciliation after cardiology phone consult.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @demo_enc_medrecon,
    5,
    1,
    'AMB',
    'Medication reconciliation',
    0,
    0,
    'No charges generated; nursing-only reconciliation session.'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000314', '-', '')),
    '2026-03-28 15:30:00',
    'Telehealth follow-up for blood pressure logs and symptom review.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @demo_enc_tele,
    5,
    1,
    'AMB',
    'Telehealth follow-up',
    1,
    0,
    'Video visit billed as telehealth professional fee.'
);

INSERT INTO form_clinical_notes (
    uuid,
    form_id,
    date,
    pid,
    encounter,
    user,
    groupname,
    authorized,
    activity,
    code,
    codetext,
    description,
    external_id,
    clinical_notes_type,
    clinical_notes_category,
    note_related_to
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000302', '-', '')),
    0,
    '2026-04-15',
    @demo_pid,
    CAST(@encounter_id AS CHAR),
    @demo_user,
    @demo_group,
    1,
    1,
    'AGENTFORGE_LAST_PLAN',
    'Last plan',
    'Continue metformin ER and lisinopril. Review home blood pressure log at next visit. Recheck A1c in 3 months.',
    'af-note-20260415',
    'Clinical Note',
    'Plan',
    'diabetes follow-up'
);

SET @clinical_note_id := LAST_INSERT_ID();

INSERT INTO forms (
    date,
    encounter,
    form_name,
    form_id,
    pid,
    user,
    groupname,
    authorized,
    deleted,
    formdir,
    provider_id
) VALUES (
    '2026-04-15 09:30:00',
    @encounter_id,
    'Clinical Notes',
    @clinical_note_id,
    @demo_pid,
    @demo_user,
    @demo_group,
    1,
    0,
    'clinical_notes',
    1
);

UPDATE form_clinical_notes
SET form_id = LAST_INSERT_ID()
WHERE id = @clinical_note_id;

INSERT INTO form_vitals (
    uuid,
    date,
    pid,
    user,
    groupname,
    authorized,
    activity,
    bps,
    bpd,
    weight,
    height,
    temperature,
    pulse,
    respiration,
    BMI,
    oxygen_saturation,
    external_id
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000801', '-', '')),
    '2026-04-15 08:25:00',
    @demo_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '142',
    '88',
    184.000000,
    65.000000,
    98.600000,
    84.000000,
    16.000000,
    30.600000,
    98.00,
    'af-vitals-20260415'
);

INSERT INTO procedure_order (
    procedure_order_id,
    uuid,
    provider_id,
    patient_id,
    encounter_id,
    date_collected,
    date_ordered,
    order_priority,
    order_status,
    clinical_hx,
    procedure_order_type,
    order_intent
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000401', '-', '')),
    1,
    @demo_pid,
    @encounter_id,
    '2026-01-09 08:00:00',
    '2026-01-09 08:00:00',
    'routine',
    'complete',
    'AgentForge demo A1c trend.',
    'laboratory_test',
    'order'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000402', '-', '')),
    1,
    @demo_pid,
    @encounter_id,
    '2026-04-10 08:00:00',
    '2026-04-10 08:00:00',
    'routine',
    'complete',
    'AgentForge demo A1c trend.',
    'laboratory_test',
    'order'
);

INSERT INTO procedure_order_code (
    procedure_order_id,
    procedure_order_seq,
    procedure_code,
    procedure_name,
    procedure_source,
    diagnoses,
    do_not_send
) VALUES
(
    90000101,
    1,
    '4548-4',
    'Hemoglobin A1c',
    '1',
    'ICD10:E11.9',
    0
),
(
    90000102,
    1,
    '4548-4',
    'Hemoglobin A1c',
    '1',
    'ICD10:E11.9',
    0
);

INSERT INTO procedure_report (
    procedure_report_id,
    uuid,
    procedure_order_id,
    procedure_order_seq,
    date_collected,
    date_report,
    source,
    specimen_num,
    report_status,
    review_status,
    report_notes
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000501', '-', '')),
    90000101,
    1,
    '2026-01-09 08:00:00',
    '2026-01-09 12:00:00',
    1,
    'AF-A1C-202601',
    'complete',
    'reviewed',
    'AgentForge demo lab report.'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000502', '-', '')),
    90000102,
    1,
    '2026-04-10 08:00:00',
    '2026-04-10 12:00:00',
    1,
    'AF-A1C-202604',
    'complete',
    'reviewed',
    'AgentForge demo lab report.'
);

INSERT INTO procedure_result (
    procedure_result_id,
    uuid,
    procedure_report_id,
    result_data_type,
    result_code,
    result_text,
    date,
    facility,
    units,
    result,
    `range`,
    abnormal,
    comments,
    result_status
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000601', '-', '')),
    90000101,
    'N',
    '4548-4',
    'Hemoglobin A1c',
    '2026-01-09 12:00:00',
    'AgentForge Demo Lab',
    '%',
    '8.2',
    '4.0-5.6',
    'high',
    'agentforge-a1c-2026-01',
    'final'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000602', '-', '')),
    90000102,
    'N',
    '4548-4',
    'Hemoglobin A1c',
    '2026-04-10 12:00:00',
    'AgentForge Demo Lab',
    '%',
    '7.4',
    '4.0-5.6',
    'high',
    'agentforge-a1c-2026-04',
    'final'
);

INSERT INTO insurance_data (
    id,
    uuid,
    type,
    provider,
    plan_name,
    policy_number,
    group_number,
    pid,
    date,
    subscriber_fname,
    subscriber_lname,
    subscriber_relationship,
    subscriber_DOB
) VALUES (
    9000410,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000c10', '-', '')),
    'primary',
    'AgentForge Demo Insurance Co.',
    'Gold PPO',
    'AF-POL-900001',
    'GRP-DEMO-01',
    @demo_pid,
    '2026-01-01',
    'Alex',
    'Testpatient',
    'self',
    '1976-04-12'
);

INSERT INTO care_teams (
    uuid,
    pid,
    status,
    team_name,
    note,
    created_by,
    updated_by
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000a01', '-', '')),
    @demo_pid,
    'active',
    'Primary care pod',
    'AgentForge seeded care team for dashboard QA.',
    1,
    1
);

SET @alex_care_team_id := LAST_INSERT_ID();

INSERT INTO care_team_member (
    care_team_id,
    user_id,
    contact_id,
    role,
    facility_id,
    provider_since,
    status,
    note,
    created_by,
    updated_by
) VALUES
(
    @alex_care_team_id,
    1,
    NULL,
    'primary_care_provider',
    3,
    '2025-01-15',
    'active',
    'PCP since intake.',
    1,
    1
),
(
    @alex_care_team_id,
    @ct_nurse_user_id,
    NULL,
    'nurse_practitioner',
    3,
    '2025-06-01',
    'active',
    'Nurse care manager for chronic disease visits.',
    1,
    1
),
(
    @alex_care_team_id,
    @ct_spec_user_id,
    NULL,
    'specialist',
    3,
    '2025-08-10',
    'active',
    'Endocrinology consultant.',
    1,
    1
),
(
    @alex_care_team_id,
    1,
    NULL,
    'case_manager',
    0,
    '2025-09-01',
    'active',
    'Care coordinator for referrals and prior auth.',
    1,
    1
);

INSERT INTO openemr_postcalendar_events (
    pc_eid,
    pc_catid,
    pc_multiple,
    pc_aid,
    pc_pid,
    pc_gid,
    pc_title,
    pc_time,
    pc_hometext,
    pc_comments,
    pc_counter,
    pc_topic,
    pc_informant,
    pc_eventDate,
    pc_endDate,
    pc_duration,
    pc_recurrtype,
    pc_recurrspec,
    pc_recurrfreq,
    pc_startTime,
    pc_endTime,
    pc_alldayevent,
    pc_location,
    pc_eventstatus,
    pc_sharing,
    pc_apptstatus,
    pc_facility,
    uuid
) VALUES (
    @demo_appt_eid,
    5,
    0,
    '1',
    '900001',
    0,
    'AF-DEMO Alex follow-up',
    '2026-07-15 09:00:00',
    'agentforge-demo-appt-alex-followup',
    0,
    0,
    1,
    NULL,
    '2026-07-15',
    '2026-07-15',
    1800,
    0,
    '',
    0,
    '09:00:00',
    '09:30:00',
    0,
    'AgentForge Demo Clinic',
    1,
    0,
    '@',
    3,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000b01', '-', ''))
);

INSERT INTO patient_data (
    id,
    uuid,
    fname,
    lname,
    DOB,
    sex,
    date,
    pubpid,
    pid,
    providerID,
    status,
    street,
    city,
    state,
    postal_code,
    phone_cell,
    email
) VALUES
(
    @poly_pid,
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000001', '-', '')),
    'Riley',
    'Medmix',
    '1954-02-20',
    'Male',
    '2026-05-16 08:00:00',
    @poly_pubpid,
    @poly_pid,
    1,
    'active',
    '200 Demo Street',
    'Faketown',
    'NY',
    '10002',
    '555-0200',
    'riley.medmix@example.invalid'
),
(
    @sparse_pid,
    UNHEX(REPLACE('90000300-0000-4000-8000-000000000001', '-', '')),
    'Jordan',
    'Sparsechart',
    '1988-11-03',
    'Female',
    '2026-06-17 08:00:00',
    @sparse_pubpid,
    @sparse_pid,
    1,
    'active',
    '300 Demo Street',
    'Faketown',
    'NY',
    '10003',
    '555-0300',
    'jordan.sparsechart@example.invalid'
),
(
    @empty_pid,
    UNHEX(REPLACE('90000400-0000-4000-8000-000000000001', '-', '')),
    'Quinn',
    'Emptychart',
    '1992-07-07',
    'Male',
    '2026-06-01 08:00:00',
    @empty_pubpid,
    @empty_pid,
    1,
    'active',
    '400 Demo Street',
    'Faketown',
    'NY',
    '10004',
    '555-0400',
    'quinn.emptychart@example.invalid'
),
(
    @partial_pid,
    UNHEX(REPLACE('90000500-0000-4000-8000-000000000001', '-', '')),
    'Taylor',
    'Partialdemo',
    NULL,
    'Female',
    '2026-06-02 08:00:00',
    @partial_pubpid,
    @partial_pid,
    1,
    'active',
    '500 Demo Street',
    'Faketown',
    'NY',
    '10005',
    '555-0500',
    'taylor.partialdemo@example.invalid'
),
(
    @layout_pid,
    UNHEX(REPLACE('90000600-0000-4000-8000-000000000001', '-', '')),
    'Morgan',
    'Longwrap',
    '1960-03-03',
    'Female',
    '2026-06-03 08:00:00',
    @layout_pubpid,
    @layout_pid,
    1,
    'inactive',
    '600 Demo Street',
    'Faketown',
    'NY',
    '10006',
    '555-0600',
    'morgan.longwrap@example.invalid'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000600-0000-4000-8000-000000000101', '-', '')),
    '2025-10-01 09:00:00',
    'medical_problem',
    'Essential hypertension with comorbid chronic kidney disease stage 3a and medication intolerance requiring quarterly titration review with extended teaching on sodium restriction home blood pressure monitoring technique and adverse',
    '2025-10-01 00:00:00',
    'ICD10:I10',
    1,
    'AgentForge layout stress: intentionally long problem title for wrapping tests.',
    @layout_pid,
    @demo_user,
    @demo_group,
    'af-p900006-long-htn'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000101', '-', '')),
    '2025-12-01 09:00:00',
    'medical_problem',
    'Atrial fibrillation',
    '2025-12-01 00:00:00',
    'ICD10:I48.91',
    1,
    'AgentForge adversarial active problem for polypharmacy routing.',
    @poly_pid,
    @demo_user,
    @demo_group,
    'af-p900002-afib'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000102', '-', '')),
    '2024-08-12 09:00:00',
    'medical_problem',
    'Type 2 diabetes mellitus',
    '2024-08-12 00:00:00',
    'ICD10:E11.9',
    1,
    'AgentForge adversarial active problem for medication context.',
    @poly_pid,
    @demo_user,
    @demo_group,
    'af-p900002-dm'
),
(
    UNHEX(REPLACE('90000300-0000-4000-8000-000000000101', '-', '')),
    '2026-06-01 09:00:00',
    'medical_problem',
    'Seasonal allergic rhinitis',
    '2026-06-01 00:00:00',
    'ICD10:J30.2',
    1,
    'AgentForge sparse-chart present problem.',
    @sparse_pid,
    @demo_user,
    @demo_group,
    'af-p900003-rh'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    reaction,
    severity_al,
    verification,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000701', '-', '')),
    '2026-05-16 09:00:00',
    'allergy',
    'Sulfonamide antibiotics',
    '2026-05-16 00:00:00',
    'hives',
    'moderate',
    'confirmed',
    1,
    'AgentForge active allergy for Riley.',
    @poly_pid,
    @demo_user,
    @demo_group,
    'af-al-p2-sulfa'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000702', '-', '')),
    '2024-01-10 09:00:00',
    'allergy',
    'Warfarin',
    '2024-01-10 00:00:00',
    'historical intolerance entered in error',
    'mild',
    'entered-in-error',
    0,
    'Inactive allergy row that must not be surfaced.',
    @poly_pid,
    @demo_user,
    @demo_group,
    'af-al-p2-warfarin'
);

INSERT INTO form_vitals (
    uuid,
    date,
    pid,
    user,
    groupname,
    authorized,
    activity,
    bps,
    bpd,
    pulse,
    external_id
) VALUES (
    UNHEX(REPLACE('90000300-0000-4000-8000-000000000801', '-', '')),
    '2024-01-10 08:00:00',
    @sparse_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '120',
    '76',
    72.000000,
    'af-vit-900003-stale'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES (
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000103', '-', '')),
    '2026-05-16 09:00:00',
    'medication',
    'Metformin ER 500 mg',
    '2026-05-16 00:00:00',
    '',
    1,
    'Near-duplicate active medication-list row for AgentForge verifier stress.',
    @poly_pid,
    @demo_user,
    @demo_group,
    'af-l900002-metdup'
);

SET @poly_metformin_list_id := LAST_INSERT_ID();

INSERT INTO lists_medication (
    id,
    list_id,
    drug_dosage_instructions,
    usage_category,
    usage_category_title,
    request_intent,
    request_intent_title,
    is_primary_record
) VALUES (
    90000203,
    @poly_metformin_list_id,
    'Patient-reported duplicate: takes metformin ER once daily with evening meal',
    'community',
    'Community',
    'order',
    'Order',
    0
);

INSERT INTO prescriptions (
    uuid,
    patient_id,
    filled_by_id,
    date_added,
    date_modified,
    provider_id,
    encounter,
    start_date,
    drug,
    dosage,
    quantity,
    route,
    refills,
    medication,
    note,
    active,
    datetime,
    user,
    txDate,
    usage_category_title,
    request_intent_title,
    drug_dosage_instructions,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000201', '-', '')),
    @poly_pid,
    1,
    '2026-05-16 10:00:00',
    '2026-05-16 10:00:00',
    1,
    @poly_encounter_id,
    '2026-05-16',
    'Apixaban 5 mg',
    '5 mg',
    '60',
    'oral',
    2,
    1,
    'Active anticoagulant for AgentForge polypharmacy patient.',
    1,
    '2026-05-16 10:00:00',
    @demo_user,
    '2026-05-16',
    'Community',
    'Order',
    'Take 1 tablet by mouth twice daily',
    'af-rx-p2-apixaban'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000202', '-', '')),
    @poly_pid,
    1,
    '2026-05-16 10:05:00',
    '2026-05-16 10:05:00',
    1,
    @poly_encounter_id,
    '2026-05-16',
    'Metformin ER 500 mg',
    '500 mg',
    '30',
    'oral',
    2,
    1,
    'Active diabetes medication for AgentForge polypharmacy patient.',
    1,
    '2026-05-16 10:05:00',
    @demo_user,
    '2026-05-16',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily with evening meal',
    'af-rx-p2-metformin'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000203', '-', '')),
    @poly_pid,
    1,
    '2025-11-20 10:00:00',
    '2026-05-16 10:10:00',
    1,
    @poly_encounter_id,
    '2025-11-20',
    'Warfarin 2 mg',
    '2 mg',
    '30',
    'oral',
    0,
    1,
    'Inactive medication that must not be promoted as active.',
    0,
    '2025-11-20 10:00:00',
    @demo_user,
    '2025-11-20',
    'Community',
    'Order',
    'Historical anticoagulant before apixaban',
    'af-rx-p2-warfarin'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000204', '-', '')),
    @poly_pid,
    1,
    '2023-01-10 10:00:00',
    '2026-05-16 10:15:00',
    1,
    @poly_encounter_id,
    '2023-01-10',
    'Simvastatin 20 mg',
    '20 mg',
    '30',
    'oral',
    0,
    1,
    'Stale inactive record older than the active-medication window.',
    0,
    '2023-01-10 10:00:00',
    @demo_user,
    '2023-01-10',
    'Community',
    'Order',
    'Historical statin record; not active',
    'af-rx-p2-simvast'
);

INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code,
    encounter_type_description
) VALUES
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000301', '-', '')),
    '2026-05-16 09:30:00',
    'Medication reconciliation for anticoagulation and diabetes follow-up.',
    'AgentForge Demo Clinic',
    3,
    @poly_pid,
    @poly_encounter_id,
    5,
    1,
    'AMB',
    'Primary care medication reconciliation'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000311', '-', '')),
    '2026-04-02 08:30:00',
    'Annual physical with preventive counseling and immunization review.',
    'AgentForge Demo Clinic',
    3,
    @poly_pid,
    @poly_enc_annual,
    5,
    1,
    'AMB',
    'Annual wellness visit'
),
(
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000312', '-', '')),
    '2026-04-28 16:00:00',
    'Telehealth urgent follow-up for palpitations and anticoagulation symptoms.',
    'AgentForge Demo Clinic',
    3,
    @poly_pid,
    @poly_enc_tele,
    5,
    1,
    'AMB',
    'Telehealth urgent visit'
),
(
    UNHEX(REPLACE('90000300-0000-4000-8000-000000000301', '-', '')),
    '2026-06-17 09:30:00',
    'Sparse chart orientation visit with limited imported data.',
    'AgentForge Demo Clinic',
    3,
    @sparse_pid,
    @sparse_encounter_id,
    5,
    1,
    'AMB',
    'Primary care sparse-chart follow-up'
);

INSERT INTO care_teams (
    uuid,
    pid,
    status,
    team_name,
    note,
    created_by,
    updated_by
) VALUES (
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000a02', '-', '')),
    @poly_pid,
    'active',
    'Cardiology collaboration',
    'AgentForge secondary care team for Riley dashboard tests.',
    1,
    1
);

SET @poly_care_team_id := LAST_INSERT_ID();

INSERT INTO care_team_member (
    care_team_id,
    user_id,
    contact_id,
    role,
    facility_id,
    provider_since,
    status,
    note,
    created_by,
    updated_by
) VALUES
(
    @poly_care_team_id,
    1,
    NULL,
    'primary_care_provider',
    3,
    '2024-01-01',
    'active',
    'Primary team anchor.',
    1,
    1
),
(
    @poly_care_team_id,
    @ct_spec_user_id,
    NULL,
    'specialist',
    3,
    '2025-11-01',
    'active',
    'Cardiology specialist on shared care plan.',
    1,
    1
);

INSERT INTO form_clinical_notes (
    uuid,
    form_id,
    date,
    pid,
    encounter,
    user,
    groupname,
    authorized,
    activity,
    code,
    codetext,
    description,
    external_id,
    clinical_notes_type,
    clinical_notes_category,
    note_related_to
) VALUES (
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000302', '-', '')),
    0,
    '2026-05-16',
    @poly_pid,
    CAST(@poly_encounter_id AS CHAR),
    @demo_user,
    @demo_group,
    1,
    1,
    'AGENTFORGE_MED_RECON',
    'Medication reconciliation plan',
    'Medication list contains active apixaban and metformin. Warfarin is documented as stopped. Duplicate metformin row should be cited separately if surfaced.',
    'af-note-900002-med-recon',
    'Clinical Note',
    'Plan',
    'polypharmacy follow-up'
);

SET @poly_clinical_note_id := LAST_INSERT_ID();

INSERT INTO forms (
    date,
    encounter,
    form_name,
    form_id,
    pid,
    user,
    groupname,
    authorized,
    deleted,
    formdir,
    provider_id
) VALUES (
    '2026-05-16 09:30:00',
    @poly_encounter_id,
    'Clinical Notes',
    @poly_clinical_note_id,
    @poly_pid,
    @demo_user,
    @demo_group,
    1,
    0,
    'clinical_notes',
    1
);

UPDATE form_clinical_notes
SET form_id = LAST_INSERT_ID()
WHERE id = @poly_clinical_note_id;

INSERT INTO procedure_order (
    procedure_order_id,
    uuid,
    provider_id,
    patient_id,
    encounter_id,
    date_collected,
    date_ordered,
    order_priority,
    order_status,
    clinical_hx,
    procedure_order_type,
    order_intent
) VALUES
(
    90000201,
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000401', '-', '')),
    1,
    @poly_pid,
    @poly_encounter_id,
    '2026-05-10 08:00:00',
    '2026-05-10 08:00:00',
    'routine',
    'complete',
    'AgentForge polypharmacy renal-function context.',
    'laboratory_test',
    'order'
);

INSERT INTO procedure_order_code (
    procedure_order_id,
    procedure_order_seq,
    procedure_code,
    procedure_name,
    procedure_source,
    diagnoses,
    do_not_send
) VALUES (
    90000201,
    1,
    '33914-3',
    'Estimated GFR',
    '1',
    'ICD10:I48.91',
    0
);

INSERT INTO procedure_report (
    procedure_report_id,
    uuid,
    procedure_order_id,
    procedure_order_seq,
    date_collected,
    date_report,
    source,
    specimen_num,
    report_status,
    review_status,
    report_notes
) VALUES (
    90000201,
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000501', '-', '')),
    90000201,
    1,
    '2026-05-10 08:00:00',
    '2026-05-10 12:00:00',
    1,
    'AF-EGFR-900002-202605',
    'complete',
    'reviewed',
    'AgentForge polypharmacy lab report.'
);

INSERT INTO procedure_result (
    procedure_result_id,
    uuid,
    procedure_report_id,
    result_data_type,
    result_code,
    result_text,
    date,
    facility,
    units,
    result,
    `range`,
    abnormal,
    comments,
    result_status
) VALUES (
    90000201,
    UNHEX(REPLACE('90000200-0000-4000-8000-000000000601', '-', '')),
    90000201,
    'N',
    '33914-3',
    'Estimated GFR',
    '2026-05-10 12:00:00',
    'AgentForge Demo Lab',
    'mL/min/1.73m2',
    '68',
    '>60',
    'no',
    'agentforge-egfr-900002-2026-05',
    'final'
);

-- HL7-aligned cohort (pid 900101-900107): invented encounters, problems, meds, vitals, and selective labs for evidence-tool QA.
INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code,
    encounter_type_description,
    last_level_billed,
    last_level_closed,
    billing_note
) VALUES
(
    UNHEX(REPLACE('90010101-0000-4000-8000-000000000301', '-', '')),
    '2026-05-10 10:00:00',
    'Synthetic primary care follow-up for chronic disease management.',
    'AgentForge Demo Clinic',
    3,
    @chen_pid,
    901101,
    5,
    1,
    'AMB',
    'Primary care',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
),
(
    UNHEX(REPLACE('90010201-0000-4000-8000-000000000301', '-', '')),
    '2026-05-11 10:00:00',
    'Synthetic pulmonary clinic follow-up.',
    'AgentForge Demo Clinic',
    3,
    @h7_whitaker_pid,
    901102,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
),
(
    UNHEX(REPLACE('90010301-0000-4000-8000-000000000301', '-', '')),
    '2026-05-12 10:00:00',
    'Synthetic endocrine follow-up visit.',
    'AgentForge Demo Clinic',
    3,
    @h7_reyes_pid,
    901103,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
),
(
    UNHEX(REPLACE('90010401-0000-4000-8000-000000000301', '-', '')),
    '2026-05-13 10:00:00',
    'Synthetic GI follow-up visit.',
    'AgentForge Demo Clinic',
    3,
    @h7_kowalski_pid,
    901104,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
),
(
    UNHEX(REPLACE('90010501-0000-4000-8000-000000000301', '-', '')),
    '2026-05-14 10:00:00',
    'Synthetic chart review with limited structured data.',
    'AgentForge Demo Clinic',
    3,
    @h7_patel_pid,
    901105,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned sparse demo encounter.'
),
(
    UNHEX(REPLACE('90010601-0000-4000-8000-000000000301', '-', '')),
    '2026-05-15 10:00:00',
    'Synthetic cardiology co-management visit.',
    'AgentForge Demo Clinic',
    3,
    @h7_johnson_pid,
    901106,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
),
(
    UNHEX(REPLACE('90010701-0000-4000-8000-000000000301', '-', '')),
    '2026-05-16 10:00:00',
    'Synthetic wellness visit with preventive counseling.',
    'AgentForge Demo Clinic',
    3,
    @h7_nguyen_pid,
    901107,
    5,
    1,
    'AMB',
    'Outpatient',
    1,
    0,
    'AgentForge HL7-aligned demo encounter.'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90010111-0000-4000-8000-000000000101', '-', '')),
    '2025-08-01 09:00:00',
    'medical_problem',
    'Essential hypertension',
    '2025-08-01 00:00:00',
    'ICD10:I10',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @chen_pid,
    @demo_user,
    @demo_group,
    'af-h7-101-htn'
),
(
    UNHEX(REPLACE('90010111-0000-4000-8000-000000000102', '-', '')),
    '2024-11-10 09:00:00',
    'medical_problem',
    'Chronic kidney disease stage 3',
    '2024-11-10 00:00:00',
    'ICD10:N18.3',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @chen_pid,
    @demo_user,
    @demo_group,
    'af-h7-101-ckd'
),
(
    UNHEX(REPLACE('90010211-0000-4000-8000-000000000101', '-', '')),
    '2023-05-20 09:00:00',
    'medical_problem',
    'Chronic obstructive pulmonary disease',
    '2023-05-20 00:00:00',
    'ICD10:J44.9',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_whitaker_pid,
    @demo_user,
    @demo_group,
    'af-h7-102-copd'
),
(
    UNHEX(REPLACE('90010211-0000-4000-8000-000000000102', '-', '')),
    '2024-01-15 09:00:00',
    'medical_problem',
    'Obstructive sleep apnea',
    '2024-01-15 00:00:00',
    'ICD10:G47.33',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_whitaker_pid,
    @demo_user,
    @demo_group,
    'af-h7-102-osa'
),
(
    UNHEX(REPLACE('90010311-0000-4000-8000-000000000101', '-', '')),
    '2022-03-10 09:00:00',
    'medical_problem',
    'Primary hypothyroidism',
    '2022-03-10 00:00:00',
    'ICD10:E03.9',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_reyes_pid,
    @demo_user,
    @demo_group,
    'af-h7-103-hypo'
),
(
    UNHEX(REPLACE('90010311-0000-4000-8000-000000000102', '-', '')),
    '2025-06-01 09:00:00',
    'medical_problem',
    'Generalized anxiety disorder',
    '2025-06-01 00:00:00',
    'ICD10:F41.1',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_reyes_pid,
    @demo_user,
    @demo_group,
    'af-h7-103-gad'
),
(
    UNHEX(REPLACE('90010411-0000-4000-8000-000000000101', '-', '')),
    '2024-09-12 09:00:00',
    'medical_problem',
    'Gastroesophageal reflux disease',
    '2024-09-12 00:00:00',
    'ICD10:K21.9',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_kowalski_pid,
    @demo_user,
    @demo_group,
    'af-h7-104-gerd'
),
(
    UNHEX(REPLACE('90010411-0000-4000-8000-000000000102', '-', '')),
    '2021-02-18 09:00:00',
    'medical_problem',
    'Osteoarthritis of bilateral knees',
    '2021-02-18 00:00:00',
    'ICD10:M17.0',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_kowalski_pid,
    @demo_user,
    @demo_group,
    'af-h7-104-oa'
),
(
    UNHEX(REPLACE('90010511-0000-4000-8000-000000000101', '-', '')),
    '2025-01-05 09:00:00',
    'medical_problem',
    'Migraine without aura',
    '2025-01-05 00:00:00',
    'ICD10:G43.009',
    1,
    'AgentForge sparse-chart demo problem.',
    @h7_patel_pid,
    @demo_user,
    @demo_group,
    'af-h7-105-migraine'
),
(
    UNHEX(REPLACE('90010511-0000-4000-8000-000000000102', '-', '')),
    '2024-04-22 09:00:00',
    'medical_problem',
    'Subclinical hypothyroidism',
    '2024-04-22 00:00:00',
    'ICD10:E02.9',
    1,
    'AgentForge sparse-chart demo problem.',
    @h7_patel_pid,
    @demo_user,
    @demo_group,
    'af-h7-105-thyroid'
),
(
    UNHEX(REPLACE('90010611-0000-4000-8000-000000000101', '-', '')),
    '2023-07-01 09:00:00',
    'medical_problem',
    'Heart failure with preserved ejection fraction',
    '2023-07-01 00:00:00',
    'ICD10:I50.32',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_johnson_pid,
    @demo_user,
    @demo_group,
    'af-h7-106-chf'
),
(
    UNHEX(REPLACE('90010611-0000-4000-8000-000000000102', '-', '')),
    '2022-11-11 09:00:00',
    'medical_problem',
    'Atrial fibrillation',
    '2022-11-11 00:00:00',
    'ICD10:I48.91',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_johnson_pid,
    @demo_user,
    @demo_group,
    'af-h7-106-afib'
),
(
    UNHEX(REPLACE('90010711-0000-4000-8000-000000000101', '-', '')),
    '2025-03-01 09:00:00',
    'medical_problem',
    'Polycystic ovary syndrome',
    '2025-03-01 00:00:00',
    'ICD10:E28.2',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_nguyen_pid,
    @demo_user,
    @demo_group,
    'af-h7-107-pcos'
),
(
    UNHEX(REPLACE('90010711-0000-4000-8000-000000000102', '-', '')),
    '2024-10-10 09:00:00',
    'medical_problem',
    'Vitamin D deficiency',
    '2024-10-10 00:00:00',
    'ICD10:E55.9',
    1,
    'AgentForge synthetic problem for HL7-aligned demo.',
    @h7_nguyen_pid,
    @demo_user,
    @demo_group,
    'af-h7-107-vitd'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    reaction,
    severity_al,
    verification,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES (
    UNHEX(REPLACE('90010611-0000-4000-8000-000000000701', '-', '')),
    '2026-01-10 09:00:00',
    'allergy',
    'Aspirin',
    '2026-01-10 00:00:00',
    'bronchospasm',
    'moderate',
    'confirmed',
    1,
    'Synthetic allergy row for allergy-tool coverage (not from HL7 fixtures).',
    @h7_johnson_pid,
    @demo_user,
    @demo_group,
    'af-h7-106-asa'
);

INSERT INTO prescriptions (
    uuid,
    patient_id,
    filled_by_id,
    date_added,
    date_modified,
    provider_id,
    encounter,
    start_date,
    drug,
    dosage,
    quantity,
    route,
    refills,
    medication,
    note,
    active,
    datetime,
    user,
    txDate,
    usage_category_title,
    request_intent_title,
    drug_dosage_instructions,
    external_id
) VALUES
(
    UNHEX(REPLACE('90010121-0000-4000-8000-000000000201', '-', '')),
    @chen_pid,
    1,
    '2026-04-01 10:00:00',
    '2026-04-01 10:00:00',
    1,
    901101,
    '2026-04-01',
    'Losartan 50 mg',
    '50 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-04-01 10:00:00',
    @demo_user,
    '2026-04-01',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily in the morning',
    'af7-101-rx-a'
),
(
    UNHEX(REPLACE('90010121-0000-4000-8000-000000000202', '-', '')),
    @chen_pid,
    1,
    '2026-04-01 10:05:00',
    '2026-04-01 10:05:00',
    1,
    901101,
    '2026-04-01',
    'Atorvastatin 20 mg',
    '20 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-04-01 10:05:00',
    @demo_user,
    '2026-04-01',
    'Community',
    'Order',
    'Take 1 tablet by mouth nightly',
    'af7-101-rx-b'
),
(
    UNHEX(REPLACE('90010221-0000-4000-8000-000000000201', '-', '')),
    @h7_whitaker_pid,
    1,
    '2026-03-20 10:00:00',
    '2026-03-20 10:00:00',
    1,
    901102,
    '2026-03-20',
    'Tiotropium inhaler 18 mcg',
    '18 mcg',
    '30',
    'inhalation',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-03-20 10:00:00',
    @demo_user,
    '2026-03-20',
    'Community',
    'Order',
    'Inhale 1 capsule daily using HandiHaler device',
    'af7-102-rx-a'
),
(
    UNHEX(REPLACE('90010221-0000-4000-8000-000000000202', '-', '')),
    @h7_whitaker_pid,
    1,
    '2026-03-20 10:05:00',
    '2026-03-20 10:05:00',
    1,
    901102,
    '2026-03-20',
    'Montelukast 10 mg',
    '10 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-03-20 10:05:00',
    @demo_user,
    '2026-03-20',
    'Community',
    'Order',
    'Take 1 tablet by mouth every evening',
    'af7-102-rx-b'
),
(
    UNHEX(REPLACE('90010321-0000-4000-8000-000000000201', '-', '')),
    @h7_reyes_pid,
    1,
    '2026-02-11 10:00:00',
    '2026-02-11 10:00:00',
    1,
    901103,
    '2026-02-11',
    'Levothyroxine 75 mcg',
    '75 mcg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-02-11 10:00:00',
    @demo_user,
    '2026-02-11',
    'Community',
    'Order',
    'Take 1 tablet by mouth every morning on empty stomach',
    'af7-103-rx-a'
),
(
    UNHEX(REPLACE('90010321-0000-4000-8000-000000000202', '-', '')),
    @h7_reyes_pid,
    1,
    '2026-02-11 10:05:00',
    '2026-02-11 10:05:00',
    1,
    901103,
    '2026-02-11',
    'Sertraline 50 mg',
    '50 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-02-11 10:05:00',
    @demo_user,
    '2026-02-11',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily',
    'af7-103-rx-b'
),
(
    UNHEX(REPLACE('90010421-0000-4000-8000-000000000201', '-', '')),
    @h7_kowalski_pid,
    1,
    '2026-01-05 10:00:00',
    '2026-01-05 10:00:00',
    1,
    901104,
    '2026-01-05',
    'Pantoprazole 40 mg',
    '40 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-01-05 10:00:00',
    @demo_user,
    '2026-01-05',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily before breakfast',
    'af7-104-rx-a'
),
(
    UNHEX(REPLACE('90010421-0000-4000-8000-000000000202', '-', '')),
    @h7_kowalski_pid,
    1,
    '2026-01-05 10:05:00',
    '2026-01-05 10:05:00',
    1,
    901104,
    '2026-01-05',
    'Acetaminophen 500 mg',
    '500 mg',
    '60',
    'oral',
    0,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-01-05 10:05:00',
    @demo_user,
    '2026-01-05',
    'Community',
    'Order',
    'Take up to 2 tablets every 6 hours as needed for pain',
    'af7-104-rx-b'
),
(
    UNHEX(REPLACE('90010621-0000-4000-8000-000000000201', '-', '')),
    @h7_johnson_pid,
    1,
    '2026-03-01 10:00:00',
    '2026-03-01 10:00:00',
    1,
    901106,
    '2026-03-01',
    'Metoprolol succinate 25 mg',
    '25 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-03-01 10:00:00',
    @demo_user,
    '2026-03-01',
    'Community',
    'Order',
    'Take 1 tablet by mouth twice daily',
    'af7-106-rx-a'
),
(
    UNHEX(REPLACE('90010621-0000-4000-8000-000000000202', '-', '')),
    @h7_johnson_pid,
    1,
    '2026-03-01 10:05:00',
    '2026-03-01 10:05:00',
    1,
    901106,
    '2026-03-01',
    'Furosemide 20 mg',
    '20 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-03-01 10:05:00',
    @demo_user,
    '2026-03-01',
    'Community',
    'Order',
    'Take 1 tablet by mouth every morning',
    'af7-106-rx-b'
),
(
    UNHEX(REPLACE('90010721-0000-4000-8000-000000000201', '-', '')),
    @h7_nguyen_pid,
    1,
    '2026-04-18 10:00:00',
    '2026-04-18 10:00:00',
    1,
    901107,
    '2026-04-18',
    'Combined oral contraceptive',
    '1 active pack',
    '3',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-04-18 10:00:00',
    @demo_user,
    '2026-04-18',
    'Community',
    'Order',
    'Take as directed on blister pack daily',
    'af7-107-rx-a'
),
(
    UNHEX(REPLACE('90010721-0000-4000-8000-000000000202', '-', '')),
    @h7_nguyen_pid,
    1,
    '2026-04-18 10:05:00',
    '2026-04-18 10:05:00',
    1,
    901107,
    '2026-04-18',
    'Cholecalciferol 2000 units',
    '2000 units',
    '90',
    'oral',
    2,
    1,
    'AgentForge synthetic medication for HL7-aligned demo.',
    1,
    '2026-04-18 10:05:00',
    @demo_user,
    '2026-04-18',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily with food',
    'af7-107-rx-b'
);

INSERT INTO form_vitals (
    uuid,
    date,
    pid,
    user,
    groupname,
    authorized,
    activity,
    bps,
    bpd,
    weight,
    height,
    temperature,
    pulse,
    respiration,
    BMI,
    oxygen_saturation,
    external_id
) VALUES
(
    UNHEX(REPLACE('90010131-0000-4000-8000-000000000801', '-', '')),
    '2026-05-10 08:25:00',
    @chen_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '138',
    '86',
    168.000000,
    64.000000,
    98.400000,
    72.000000,
    14.000000,
    28.800000,
    98.00,
    'af-h7-vit-101'
),
(
    UNHEX(REPLACE('90010231-0000-4000-8000-000000000801', '-', '')),
    '2026-05-11 08:25:00',
    @h7_whitaker_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '128',
    '78',
    198.000000,
    70.000000,
    98.200000,
    68.000000,
    14.000000,
    28.400000,
    96.00,
    'af-h7-vit-102'
),
(
    UNHEX(REPLACE('90010331-0000-4000-8000-000000000801', '-', '')),
    '2026-05-12 08:25:00',
    @h7_reyes_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '118',
    '74',
    152.000000,
    63.000000,
    98.000000,
    76.000000,
    15.000000,
    27.000000,
    99.00,
    'af-h7-vit-103'
),
(
    UNHEX(REPLACE('90010431-0000-4000-8000-000000000801', '-', '')),
    '2026-05-13 08:25:00',
    @h7_kowalski_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '132',
    '82',
    210.000000,
    72.000000,
    98.500000,
    70.000000,
    16.000000,
    28.500000,
    97.00,
    'af-h7-vit-104'
),
(
    UNHEX(REPLACE('90010631-0000-4000-8000-000000000801', '-', '')),
    '2026-05-15 08:25:00',
    @h7_johnson_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '146',
    '90',
    220.000000,
    69.000000,
    98.300000,
    88.000000,
    18.000000,
    32.500000,
    95.00,
    'af-h7-vit-106'
),
(
    UNHEX(REPLACE('90010731-0000-4000-8000-000000000801', '-', '')),
    '2026-05-16 08:25:00',
    @h7_nguyen_pid,
    @demo_user,
    @demo_group,
    1,
    1,
    '112',
    '70',
    138.000000,
    65.000000,
    98.100000,
    68.000000,
    14.000000,
    23.000000,
    99.00,
    'af-h7-vit-107'
);

INSERT INTO form_clinical_notes (
    uuid,
    form_id,
    date,
    pid,
    encounter,
    user,
    groupname,
    authorized,
    activity,
    code,
    codetext,
    description,
    external_id,
    clinical_notes_type,
    clinical_notes_category,
    note_related_to
) VALUES (
    UNHEX(REPLACE('90010141-0000-4000-8000-000000000302', '-', '')),
    0,
    '2026-05-10',
    @chen_pid,
    '901101',
    @demo_user,
    @demo_group,
    1,
    1,
    'AGENTFORGE_SYNTH_PLAN',
    'Synthetic plan',
    'Continue losartan and atorvastatin. Recheck BMP and lipids in 8 weeks. Home BP log reviewed.',
    'af-h7-note-101',
    'Clinical Note',
    'Plan',
    'hypertension follow-up'
);

SET @h7_chen_note_id := LAST_INSERT_ID();

INSERT INTO forms (
    date,
    encounter,
    form_name,
    form_id,
    pid,
    user,
    groupname,
    authorized,
    deleted,
    formdir,
    provider_id
) VALUES (
    '2026-05-10 10:00:00',
    901101,
    'Clinical Notes',
    @h7_chen_note_id,
    @chen_pid,
    @demo_user,
    @demo_group,
    1,
    0,
    'clinical_notes',
    1
);

UPDATE form_clinical_notes
SET form_id = LAST_INSERT_ID()
WHERE id = @h7_chen_note_id;

INSERT INTO procedure_order (
    procedure_order_id,
    uuid,
    provider_id,
    patient_id,
    encounter_id,
    date_collected,
    date_ordered,
    order_priority,
    order_status,
    clinical_hx,
    procedure_order_type,
    order_intent
) VALUES
(
    901211,
    UNHEX(REPLACE('90010151-0000-4000-8000-000000000401', '-', '')),
    1,
    @chen_pid,
    901101,
    '2026-04-20 08:00:00',
    '2026-04-20 08:00:00',
    'routine',
    'complete',
    'AgentForge synthetic A1c for HL7-aligned demo.',
    'laboratory_test',
    'order'
),
(
    901212,
    UNHEX(REPLACE('90010351-0000-4000-8000-000000000401', '-', '')),
    1,
    @h7_reyes_pid,
    901103,
    '2026-04-22 08:00:00',
    '2026-04-22 08:00:00',
    'routine',
    'complete',
    'AgentForge synthetic CBC for HL7-aligned demo.',
    'laboratory_test',
    'order'
),
(
    901213,
    UNHEX(REPLACE('90010751-0000-4000-8000-000000000401', '-', '')),
    1,
    @h7_nguyen_pid,
    901107,
    '2026-04-25 08:00:00',
    '2026-04-25 08:00:00',
    'routine',
    'complete',
    'AgentForge synthetic TSH for HL7-aligned demo.',
    'laboratory_test',
    'order'
);

INSERT INTO procedure_order_code (
    procedure_order_id,
    procedure_order_seq,
    procedure_code,
    procedure_name,
    procedure_source,
    diagnoses,
    do_not_send
) VALUES
(
    901211,
    1,
    '4548-4',
    'Hemoglobin A1c',
    '1',
    'ICD10:I10',
    0
),
(
    901212,
    1,
    '718-7',
    'Hemoglobin',
    '1',
    'ICD10:E03.9',
    0
),
(
    901213,
    1,
    '3016-0',
    'Thyrotropin [Units/volume] in Serum or Plasma',
    '1',
    'ICD10:E28.2',
    0
);

INSERT INTO procedure_report (
    procedure_report_id,
    uuid,
    procedure_order_id,
    procedure_order_seq,
    date_collected,
    date_report,
    source,
    specimen_num,
    report_status,
    review_status,
    report_notes
) VALUES
(
    901211,
    UNHEX(REPLACE('90010151-0000-4000-8000-000000000501', '-', '')),
    901211,
    1,
    '2026-04-20 08:00:00',
    '2026-04-20 12:00:00',
    1,
    'AF-H7-A1C-101',
    'complete',
    'reviewed',
    'AgentForge HL7-aligned demo lab.'
),
(
    901212,
    UNHEX(REPLACE('90010351-0000-4000-8000-000000000501', '-', '')),
    901212,
    1,
    '2026-04-22 08:00:00',
    '2026-04-22 12:00:00',
    1,
    'AF-H7-CBC-103',
    'complete',
    'reviewed',
    'AgentForge HL7-aligned demo lab.'
),
(
    901213,
    UNHEX(REPLACE('90010751-0000-4000-8000-000000000501', '-', '')),
    901213,
    1,
    '2026-04-25 08:00:00',
    '2026-04-25 12:00:00',
    1,
    'AF-H7-TSH-107',
    'complete',
    'reviewed',
    'AgentForge HL7-aligned demo lab.'
);

INSERT INTO procedure_result (
    procedure_result_id,
    uuid,
    procedure_report_id,
    result_data_type,
    result_code,
    result_text,
    date,
    facility,
    units,
    result,
    `range`,
    abnormal,
    comments,
    result_status
) VALUES
(
    901211,
    UNHEX(REPLACE('90010151-0000-4000-8000-000000000601', '-', '')),
    901211,
    'N',
    '4548-4',
    'Hemoglobin A1c',
    '2026-04-20 12:00:00',
    'AgentForge Demo Lab',
    '%',
    '6.9',
    '4.0-5.6',
    'high',
    'agentforge-h7-a1c-101',
    'final'
),
(
    901212,
    UNHEX(REPLACE('90010351-0000-4000-8000-000000000601', '-', '')),
    901212,
    'N',
    '718-7',
    'Hemoglobin',
    '2026-04-22 12:00:00',
    'AgentForge Demo Lab',
    'g/dL',
    '13.2',
    '12.0-15.5',
    'no',
    'agentforge-h7-hgb-103',
    'final'
),
(
    901213,
    UNHEX(REPLACE('90010751-0000-4000-8000-000000000601', '-', '')),
    901213,
    'N',
    '3016-0',
    'Thyroid stimulating hormone',
    '2026-04-25 12:00:00',
    'AgentForge Demo Lab',
    'mIU/L',
    '2.1',
    '0.4-4.0',
    'no',
    'agentforge-h7-tsh-107',
    'final'
);

-- Week 2 clinical document extraction mappings.
SET @lab_pdf_cat_id := (SELECT id FROM categories WHERE name = 'Lab Report' LIMIT 1);
SET @intake_form_cat_exists := (SELECT COUNT(*) FROM categories WHERE name = 'Intake Form');
SET @intake_form_cat_id := (SELECT id FROM categories WHERE name = 'Intake Form' LIMIT 1);
SET @category_root_rght := (SELECT rght FROM categories WHERE id = 1 LIMIT 1);

INSERT INTO categories (id, name, value, parent, lft, rght, aco_spec, codes)
SELECT 900101, 'Intake Form', '', 1, @category_root_rght, @category_root_rght + 1, 'patients|docs', ''
WHERE @intake_form_cat_exists = 0;

UPDATE categories
SET rght = rght + 2
WHERE id = 1
    AND @intake_form_cat_exists = 0;

SET @intake_form_cat_id := (SELECT id FROM categories WHERE name = 'Intake Form' LIMIT 1);

INSERT INTO clinical_document_type_mappings (category_id, doc_type, active, created_at)
SELECT @lab_pdf_cat_id, 'lab_pdf', 1, NOW()
WHERE @lab_pdf_cat_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM clinical_document_type_mappings
        WHERE category_id = @lab_pdf_cat_id
    );

INSERT INTO clinical_document_type_mappings (category_id, doc_type, active, created_at)
SELECT @intake_form_cat_id, 'intake_form', 1, NOW()
WHERE @intake_form_cat_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM clinical_document_type_mappings
        WHERE category_id = @intake_form_cat_id
    );

COMMIT;
