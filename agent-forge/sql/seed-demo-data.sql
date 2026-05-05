-- AgentForge demo data.
-- Idempotent for fake patients pid=900001, 900002, and 900003. This script never drops tables.

SET @demo_pid := 900001;
SET @demo_pubpid := 'AF-DEMO-900001';
SET @poly_pid := 900002;
SET @poly_pubpid := 'AF-DEMO-900002';
SET @sparse_pid := 900003;
SET @sparse_pubpid := 'AF-DEMO-900003';
SET @demo_user := 'admin';
SET @demo_group := 'Default';
SET @encounter_id := 900415;
SET @poly_encounter_id := 900516;
SET @sparse_encounter_id := 900617;
SET @unrelated_user_id := 900004;
SET @unrelated_aro_id := 900004;
SET @unrelated_username := 'af_demo_unrelated';
SET @admin_acl_group_id := 11;

START TRANSACTION;

DELETE FROM gacl_groups_aro_map WHERE aro_id = @unrelated_aro_id;
DELETE FROM gacl_aro WHERE id = @unrelated_aro_id OR (section_value = 'users' AND value = @unrelated_username);
DELETE FROM groups WHERE user = @unrelated_username;
DELETE FROM users_secure WHERE id = @unrelated_user_id OR username = @unrelated_username;
DELETE FROM users WHERE id = @unrelated_user_id OR username = @unrelated_username;

DELETE pr
FROM procedure_result pr
INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid);

DELETE rep
FROM procedure_report rep
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid);

DELETE poc
FROM procedure_order_code poc
INNER JOIN procedure_order po ON po.procedure_order_id = poc.procedure_order_id
WHERE po.patient_id IN (@demo_pid, @poly_pid, @sparse_pid);

DELETE FROM procedure_order WHERE patient_id IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM forms WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM form_vitals WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM form_clinical_notes WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM form_encounter WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM prescriptions WHERE patient_id IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE lm
FROM lists_medication lm
LEFT JOIN lists l ON l.id = lm.list_id
WHERE lm.id = 90000203
    AND (l.pid IN (@demo_pid, @poly_pid, @sparse_pid) OR l.id IS NULL);
DELETE lm
FROM lists_medication lm
INNER JOIN lists l ON l.id = lm.list_id
WHERE l.pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM lists WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid);
DELETE FROM patient_data
WHERE pid IN (@demo_pid, @poly_pid, @sparse_pid)
    OR pubpid IN (@demo_pubpid, @poly_pubpid, @sparse_pubpid);

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
    'alex.testpatient@example.invalid'
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
    encounter_type_description
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
    'Primary care follow-up'
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

-- Week 2 clinical document extraction mappings.
SET @lab_pdf_cat_id := (SELECT id FROM categories WHERE name = 'Lab Report' LIMIT 1);

INSERT INTO clinical_document_type_mappings (category_id, doc_type, active, created_at)
SELECT @lab_pdf_cat_id, 'lab_pdf', 1, NOW()
WHERE @lab_pdf_cat_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM clinical_document_type_mappings
        WHERE category_id = @lab_pdf_cat_id
    );

COMMIT;
