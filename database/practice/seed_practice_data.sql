-- =============================================================================
--  Practice data: 10,000+ rows in every data table of a tenant database.
--
--  Meant for a COPY of a tenant database, never the live one — it adds 10,000
--  branches, roles and staff, which would bury the app's own pickers. Make a
--  copy, then load it:
--
--    createdb -h 127.0.0.1 -U postgres hms_practice
--    pg_dump  -h 127.0.0.1 -U postgres hms_tenant_testing_vendor | psql -h 127.0.0.1 -U postgres -d hms_practice
--    psql     -h 127.0.0.1 -U postgres -d hms_practice -f database/practice/seed_practice_data.sql
--
--  To start over: DROP DATABASE hms_practice, and repeat the three steps.
--
--  Everything is generated with generate_series and set-based INSERT ... SELECT,
--  so the whole script runs in one transaction in well under a minute. Rows keep
--  the rules the application keeps: every CHECK and unique index holds, stock
--  ledger rows chain (quantity_after = quantity_before + quantity), batch
--  balances equal the sum of their ledger, doctors only see patients on the
--  weekdays their schedules cover (weekday 0 = Monday, as in App\Support\Opd\Weekday).
--
--  Config tables (entity_field_settings, entity_labels, email_templates,
--  mail_settings, files, personal_access_tokens, migrations) are left alone.
-- =============================================================================

\set ON_ERROR_STOP on
\timing on

BEGIN;

SET LOCAL timezone = 'Asia/Kolkata';
SELECT setseed(0.2026);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM locations WHERE code LIKE 'PRAC-L%') THEN
        RAISE EXCEPTION 'Practice data is already loaded in this database — drop it and re-clone to start over.';
    END IF;
END $$;

-- -----------------------------------------------------------------------------
--  Helpers (pg_temp: they vanish with the session)
-- -----------------------------------------------------------------------------

CREATE FUNCTION pg_temp.pick(a text[]) RETURNS text LANGUAGE sql VOLATILE AS
$$ SELECT a[1 + floor(random() * array_length(a, 1))::int] $$;

CREATE FUNCTION pg_temp.rand_int(lo int, hi int) RETURNS int LANGUAGE sql VOLATILE AS
$$ SELECT lo + floor(random() * (hi - lo + 1))::int $$;

-- A 10-digit number that looks random but is unique per n (48271 is coprime to 999999999).
CREATE FUNCTION pg_temp.phone(prefix text, n bigint) RETURNS text LANGUAGE sql IMMUTABLE AS
$$ SELECT prefix || lpad(((n * 48271) % 999999999)::text, 9, '0') $$;

CREATE TEMP TABLE t_mark AS
SELECT (SELECT coalesce(max(id), 0) FROM customers)    AS customer_max,
       (SELECT coalesce(max(id), 0) FROM appointments) AS appointment_max;

CREATE TEMP TABLE t_city (k int PRIMARY KEY, city text, district text, state text, pin int);
INSERT INTO t_city VALUES
    (1, 'New Delhi', 'New Delhi', 'Delhi', 110),
    (2, 'Noida', 'Gautam Buddh Nagar', 'Uttar Pradesh', 201),
    (3, 'Gurugram', 'Gurugram', 'Haryana', 122),
    (4, 'Mumbai', 'Mumbai', 'Maharashtra', 400),
    (5, 'Pune', 'Pune', 'Maharashtra', 411),
    (6, 'Nashik', 'Nashik', 'Maharashtra', 422),
    (7, 'Bengaluru', 'Bengaluru Urban', 'Karnataka', 560),
    (8, 'Mysuru', 'Mysuru', 'Karnataka', 570),
    (9, 'Chennai', 'Chennai', 'Tamil Nadu', 600),
    (10, 'Coimbatore', 'Coimbatore', 'Tamil Nadu', 641),
    (11, 'Hyderabad', 'Hyderabad', 'Telangana', 500),
    (12, 'Kolkata', 'Kolkata', 'West Bengal', 700),
    (13, 'Ahmedabad', 'Ahmedabad', 'Gujarat', 380),
    (14, 'Surat', 'Surat', 'Gujarat', 395),
    (15, 'Jaipur', 'Jaipur', 'Rajasthan', 302),
    (16, 'Lucknow', 'Lucknow', 'Uttar Pradesh', 226),
    (17, 'Kanpur', 'Kanpur Nagar', 'Uttar Pradesh', 208),
    (18, 'Varanasi', 'Varanasi', 'Uttar Pradesh', 221),
    (19, 'Patna', 'Patna', 'Bihar', 800),
    (20, 'Bhopal', 'Bhopal', 'Madhya Pradesh', 462),
    (21, 'Indore', 'Indore', 'Madhya Pradesh', 452),
    (22, 'Chandigarh', 'Chandigarh', 'Chandigarh', 160),
    (23, 'Kochi', 'Ernakulam', 'Kerala', 682),
    (24, 'Thiruvananthapuram', 'Thiruvananthapuram', 'Kerala', 695),
    (25, 'Bhubaneswar', 'Khordha', 'Odisha', 751),
    (26, 'Guwahati', 'Kamrup Metropolitan', 'Assam', 781),
    (27, 'Dehradun', 'Dehradun', 'Uttarakhand', 248),
    (28, 'Nagpur', 'Nagpur', 'Maharashtra', 440),
    (29, 'Visakhapatnam', 'Visakhapatnam', 'Andhra Pradesh', 530),
    (30, 'Ranchi', 'Ranchi', 'Jharkhand', 834);

CREATE TEMP TABLE t_words AS SELECT
    ARRAY['Aarav','Vivaan','Aditya','Vihaan','Arjun','Sai','Reyansh','Ayaan','Krishna','Ishaan',
          'Rohan','Rahul','Amit','Suresh','Rajesh','Vikram','Anil','Sanjay','Manoj','Deepak',
          'Karan','Nikhil','Pranav','Harsh','Kabir','Yash','Varun','Siddharth','Mohit','Gaurav',
          'Imran','Farhan','Joseph','Thomas','Gurpreet','Abhishek','Naveen','Ramesh','Venkat','Tarun'] AS male,
    ARRAY['Aadhya','Ananya','Diya','Saanvi','Myra','Ira','Pari','Anika','Kavya','Priya',
          'Neha','Pooja','Sneha','Riya','Anjali','Meera','Lakshmi','Sunita','Kavita','Rekha',
          'Divya','Shreya','Nisha','Swati','Aisha','Fatima','Sara','Mary','Simran','Jaspreet',
          'Tanvi','Isha','Nandini','Aparna','Bhavna','Geeta','Radha','Deepika','Pallavi','Zoya'] AS female,
    ARRAY['Sharma','Verma','Gupta','Singh','Kumar','Patel','Shah','Mehta','Iyer','Nair',
          'Reddy','Rao','Naidu','Pillai','Menon','Das','Banerjee','Chatterjee','Mukherjee','Bose',
          'Ghosh','Joshi','Kulkarni','Deshpande','Patil','Jadhav','Chauhan','Rathore','Yadav','Mishra',
          'Tiwari','Pandey','Dubey','Saxena','Srivastava','Agarwal','Bansal','Malhotra','Kapoor','Khanna',
          'Sethi','Gill','Sandhu','Khan','Sheikh','Qureshi','Fernandes','Dsouza','Thomas','Kaur'] AS last,
    ARRAY['MG Road','Station Road','Civil Lines','Gandhi Nagar','Nehru Place','Park Street','Ring Road',
          'Mall Road','Sector 18','Anna Salai','Linking Road','Brigade Road','Jubilee Hills','Salt Lake',
          'Model Town','Rajpur Road','Hazratganj','Banjara Hills','Koregaon Park','Indiranagar'] AS streets,
    ARRAY['Central','North','South','East','West','City Centre','Market','Station','Highway','Old Town',
          'New Town','Cantonment'] AS areas,
    ARRAY['Paracetamol 650mg','Azithromycin 500mg','Amoxicillin 500mg','Cetirizine 10mg','Pantoprazole 40mg',
          'Metformin 500mg','Amlodipine 5mg','Telmisartan 40mg','Atorvastatin 10mg','Ibuprofen 400mg',
          'Montelukast 10mg','Ondansetron 4mg','Cefixime 200mg','Vitamin D3 60000IU','Iron + Folic Acid',
          'Salbutamol inhaler','Ambroxol syrup','Clotrimazole cream','Prednisolone 10mg','ORS sachet'] AS drugs;

-- Specialisations with the qualifications and fee range that go with them.
CREATE TEMP TABLE t_spec (k int PRIMARY KEY, name text, quals jsonb, fee_lo int, fee_hi int);
INSERT INTO t_spec VALUES
    (1, 'General Medicine', '["MBBS","MD"]', 300, 700),
    (2, 'General Medicine', '["MBBS"]', 200, 500),
    (3, 'Cardiology', '["MBBS","MD","DM"]', 800, 1500),
    (4, 'Paediatrics', '["MBBS","DCH"]', 400, 900),
    (5, 'Dermatology', '["MBBS","MD"]', 500, 1200),
    (6, 'Orthopaedics', '["MBBS","MS"]', 600, 1200),
    (7, 'Gynaecology', '["MBBS","MS","DGO"]', 500, 1100),
    (8, 'ENT', '["MBBS","MS"]', 400, 900),
    (9, 'Ophthalmology', '["MBBS","MS"]', 400, 900),
    (10, 'Neurology', '["MBBS","MD","DM"]', 900, 1500),
    (11, 'Psychiatry', '["MBBS","MD"]', 700, 1500),
    (12, 'Dentistry', '["BDS","MDS"]', 300, 800),
    (13, 'Pulmonology', '["MBBS","MD"]', 600, 1200),
    (14, 'Gastroenterology', '["MBBS","MD","DM"]', 800, 1500),
    (15, 'Endocrinology', '["MBBS","MD","DM"]', 800, 1500),
    (16, 'Urology', '["MBBS","MS","MCh"]', 800, 1400),
    (17, 'Nephrology', '["MBBS","MD","DM"]', 800, 1400),
    (18, 'Oncology', '["MBBS","MD","DM"]', 1000, 2000),
    (19, 'Physiotherapy', '["BPT","MPT"]', 300, 700),
    (20, 'Ayurveda', '["BAMS"]', 200, 500);

-- Complaint → diagnosis → the investigation a doctor would order for it.
CREATE TEMP TABLE t_dx (k int PRIMARY KEY, complaint text, diagnosis text, test text);
INSERT INTO t_dx VALUES
    (1, 'Fever and body ache for 3 days', 'Viral fever', 'Complete Blood Count'),
    (2, 'Cough and cold', 'Upper respiratory tract infection', 'Chest X-ray'),
    (3, 'Headache and dizziness', 'Hypertension', 'Lipid profile'),
    (4, 'Frequent urination and thirst', 'Type 2 diabetes mellitus', 'HbA1c'),
    (5, 'Burning in stomach after meals', 'Gastritis', 'H. pylori antigen'),
    (6, 'Loose stools since yesterday', 'Acute gastroenteritis', 'Stool routine'),
    (7, 'Pain in both knees', 'Osteoarthritis', 'X-ray knee AP/Lat'),
    (8, 'Skin rash and itching', 'Allergic dermatitis', 'Serum IgE'),
    (9, 'Breathlessness on exertion', 'Bronchial asthma', 'Spirometry'),
    (10, 'Chest pain on walking', 'Stable angina', 'ECG'),
    (11, 'Lower back pain', 'Lumbar spondylosis', 'X-ray lumbar spine'),
    (12, 'Sore throat', 'Acute pharyngitis', 'Throat swab culture'),
    (13, 'Ear pain', 'Otitis media', 'Audiometry'),
    (14, 'Painful urination', 'Urinary tract infection', 'Urine culture'),
    (15, 'Fatigue and weakness', 'Iron deficiency anaemia', 'Serum ferritin'),
    (16, 'Anxiety and poor sleep', 'Generalised anxiety disorder', 'Thyroid profile'),
    (17, 'Red itchy eyes', 'Allergic conjunctivitis', 'Visual acuity'),
    (18, 'Irregular periods', 'Polycystic ovary syndrome', 'Pelvic ultrasound'),
    (19, 'Child with fever and cough', 'Bronchiolitis', 'Complete Blood Count'),
    (20, 'Toothache', 'Dental caries', 'Dental X-ray (IOPA)');

-- Medicine catalogue: generic, form, route, unit, category, schedule, strengths,
-- brand root, pack size and a per-unit MRP range.
CREATE TEMP TABLE t_generic (
    k serial PRIMARY KEY, generic text, form text, route text, unit text, category text,
    schedule text, strengths text[], root text, pack int, price_lo numeric, price_hi numeric
);
INSERT INTO t_generic (generic, form, route, unit, category, schedule, strengths, root, pack, price_lo, price_hi) VALUES
    ('Paracetamol', 'tablet', 'oral', 'tablet', 'Analgesic', 'OTC', '{500mg,650mg}', 'Paramol', 15, 1, 3),
    ('Ibuprofen', 'tablet', 'oral', 'tablet', 'NSAID', 'G', '{200mg,400mg,600mg}', 'Ibuzen', 10, 1.5, 4),
    ('Diclofenac', 'tablet', 'oral', 'tablet', 'NSAID', 'H', '{50mg,75mg,100mg}', 'Diclowin', 10, 2, 6),
    ('Diclofenac', 'gel', 'topical', 'tube', 'NSAID', 'OTC', '{1%}', 'Diclowin', 1, 80, 180),
    ('Amoxicillin', 'capsule', 'oral', 'capsule', 'Antibiotic', 'H', '{250mg,500mg}', 'Amoxil', 10, 5, 12),
    ('Amoxicillin + Clavulanic Acid', 'tablet', 'oral', 'tablet', 'Antibiotic', 'H', '{375mg,625mg,1g}', 'Clavomox', 6, 15, 35),
    ('Azithromycin', 'tablet', 'oral', 'tablet', 'Antibiotic', 'H', '{250mg,500mg}', 'Azimax', 3, 20, 40),
    ('Ciprofloxacin', 'tablet', 'oral', 'tablet', 'Antibiotic', 'H', '{250mg,500mg}', 'Ciprowin', 10, 3, 9),
    ('Ciprofloxacin', 'drops', 'ophthalmic', 'bottle', 'Antibiotic', 'H', '{0.3%}', 'Ciplodrop', 1, 20, 45),
    ('Cefixime', 'tablet', 'oral', 'tablet', 'Antibiotic', 'H', '{100mg,200mg}', 'Cefizone', 10, 8, 18),
    ('Ceftriaxone', 'injection', 'iv', 'vial', 'Antibiotic', 'H', '{250mg,500mg,1g}', 'Ceftrax', 1, 40, 120),
    ('Doxycycline', 'capsule', 'oral', 'capsule', 'Antibiotic', 'H', '{100mg}', 'Doxyclin', 10, 3, 8),
    ('Metformin', 'tablet', 'oral', 'tablet', 'Antidiabetic', 'H', '{500mg,850mg,1000mg}', 'Glymet', 15, 1, 4),
    ('Glimepiride', 'tablet', 'oral', 'tablet', 'Antidiabetic', 'H', '{1mg,2mg,4mg}', 'Glimrise', 10, 3, 10),
    ('Insulin Glargine', 'injection', 'sc', 'vial', 'Antidiabetic', 'H', '{100IU/ml}', 'Glarlin', 1, 400, 900),
    ('Amlodipine', 'tablet', 'oral', 'tablet', 'Antihypertensive', 'H', '{2.5mg,5mg,10mg}', 'Amlocard', 15, 1, 4),
    ('Telmisartan', 'tablet', 'oral', 'tablet', 'Antihypertensive', 'H', '{20mg,40mg,80mg}', 'Telmivas', 15, 3, 10),
    ('Losartan', 'tablet', 'oral', 'tablet', 'Antihypertensive', 'H', '{25mg,50mg}', 'Losacor', 10, 2, 7),
    ('Atorvastatin', 'tablet', 'oral', 'tablet', 'Statin', 'H', '{10mg,20mg,40mg}', 'Atorlip', 15, 4, 14),
    ('Rosuvastatin', 'tablet', 'oral', 'tablet', 'Statin', 'H', '{5mg,10mg,20mg}', 'Rosulip', 15, 6, 20),
    ('Aspirin', 'tablet', 'oral', 'tablet', 'Antiplatelet', 'OTC', '{75mg,150mg}', 'Ecospirin', 14, 0.5, 2),
    ('Clopidogrel', 'tablet', 'oral', 'tablet', 'Antiplatelet', 'H', '{75mg}', 'Clopivas', 15, 4, 12),
    ('Pantoprazole', 'tablet', 'oral', 'tablet', 'Antacid', 'H', '{20mg,40mg}', 'Pantocid', 15, 3, 10),
    ('Omeprazole', 'capsule', 'oral', 'capsule', 'Antacid', 'H', '{20mg}', 'Omezol', 15, 2, 6),
    ('Ranitidine', 'tablet', 'oral', 'tablet', 'Antacid', 'OTC', '{150mg,300mg}', 'Ranitin', 10, 1, 3),
    ('Ondansetron', 'tablet', 'oral', 'tablet', 'Antiemetic', 'H', '{4mg,8mg}', 'Ondem', 10, 4, 10),
    ('Cetirizine', 'tablet', 'oral', 'tablet', 'Antihistamine', 'OTC', '{10mg}', 'Cetzine', 10, 1, 3),
    ('Levocetirizine', 'tablet', 'oral', 'tablet', 'Antihistamine', 'G', '{5mg}', 'Levocet', 10, 2, 5),
    ('Montelukast', 'tablet', 'oral', 'tablet', 'Antiasthmatic', 'H', '{4mg,10mg}', 'Montair', 10, 8, 20),
    ('Salbutamol', 'inhaler', 'inhalation', 'unit', 'Bronchodilator', 'H', '{100mcg}', 'Asthalin', 1, 120, 250),
    ('Budesonide', 'inhaler', 'inhalation', 'unit', 'Corticosteroid', 'H', '{200mcg,400mcg}', 'Budecort', 1, 300, 650),
    ('Prednisolone', 'tablet', 'oral', 'tablet', 'Corticosteroid', 'H', '{5mg,10mg,20mg}', 'Predniwin', 10, 1, 4),
    ('Dextromethorphan', 'syrup', 'oral', 'bottle', 'Antitussive', 'OTC', '{100ml}', 'Coughex', 1, 70, 140),
    ('Ambroxol', 'syrup', 'oral', 'bottle', 'Mucolytic', 'OTC', '{15mg/5ml,30mg/5ml}', 'Ambrolite', 1, 60, 120),
    ('Clotrimazole', 'cream', 'topical', 'tube', 'Antifungal', 'OTC', '{1%}', 'Candiderm', 1, 50, 120),
    ('Mupirocin', 'ointment', 'topical', 'tube', 'Antibiotic', 'H', '{2%}', 'Mupicin', 1, 90, 200),
    ('Calamine', 'lotion', 'topical', 'bottle', 'Dermatological', 'OTC', '{8%}', 'Calacool', 1, 60, 130),
    ('Oral Rehydration Salts', 'powder', 'oral', 'sachet', 'Electrolyte', 'OTC', '{21g}', 'Electrolite', 1, 15, 30),
    ('Cholecalciferol', 'capsule', 'oral', 'capsule', 'Supplement', 'OTC', '{60000IU}', 'D-Rise', 4, 20, 45),
    ('Ferrous Ascorbate + Folic Acid', 'tablet', 'oral', 'tablet', 'Supplement', 'OTC', '{100mg+1.5mg}', 'Fefol', 10, 5, 12),
    ('Alprazolam', 'tablet', 'oral', 'tablet', 'Anxiolytic', 'H1', '{0.25mg,0.5mg}', 'Alprax', 10, 2, 6),
    ('Tramadol', 'capsule', 'oral', 'capsule', 'Opioid Analgesic', 'H1', '{50mg}', 'Tramazac', 10, 4, 10),
    ('Nicotine', 'patch', 'topical', 'unit', 'Smoking Cessation', 'OTC', '{7mg,14mg,21mg}', 'Nicopatch', 7, 40, 90);

-- =============================================================================
--  1. Branches, roles, staff
-- =============================================================================

INSERT INTO locations (name, code, type, is_active, address, city, state, pincode, phone, email,
                       gstin, drug_license_no, drug_license_expiry_date, created_at, updated_at)
SELECT c.city || ' ' || pg_temp.pick(w.areas) || ' #' || s.g,
       'PRAC-L' || lpad(s.g::text, 5, '0'),
       s.type,
       random() > 0.06,
       pg_temp.rand_int(1, 400) || ', ' || pg_temp.pick(w.streets) || ', ' || c.city,
       c.city, c.state,
       (c.pin * 1000 + pg_temp.rand_int(1, 99))::text,
       pg_temp.phone('9', s.g),
       'branch' || s.g || '@practice.test',
       CASE WHEN s.type <> 'DOCTOR_VISITING_LOCATION'
            THEN lpad(c.k::text, 2, '0') || 'AAACH' || lpad((s.g % 10000)::text, 4, '0') || chr(65 + s.g / 10000) || '1Z' || (s.g % 10)
       END,
       CASE WHEN s.type IN ('RETAIL_STORE', 'WHOLESALE_STORE', 'WAREHOUSE') THEN 'DL-' || upper(left(c.state, 2)) || '-20B-' || (100000 + s.g) END,
       CASE WHEN s.type IN ('RETAIL_STORE', 'WHOLESALE_STORE', 'WAREHOUSE') THEN current_date + pg_temp.rand_int(-90, 1800) END,
       s.created_at, s.created_at
FROM (SELECT g, pg_temp.rand_int(1, 30) AS ck,
             pg_temp.pick(ARRAY['CLINIC','CLINIC','CLINIC','CLINIC','RETAIL_STORE','RETAIL_STORE',
                                'WHOLESALE_STORE','WAREHOUSE','DOCTOR_VISITING_LOCATION']) AS type,
             now()::timestamp - interval '500 days' - random() * interval '400 days' AS created_at
      FROM generate_series(1, 10000) g OFFSET 0) s
JOIN t_city c ON c.k = s.ck
CROSS JOIN t_words w
ORDER BY s.g;

CREATE TEMP TABLE t_loc AS
SELECT id, row_number() OVER (ORDER BY id)::int AS rn FROM locations WHERE code LIKE 'PRAC-L%';
CREATE UNIQUE INDEX ON t_loc (rn);

INSERT INTO location_modules (location_id, module_key, is_enabled, created_at, updated_at)
SELECT l.id, m.key, random() < 0.75, now(), now()
FROM t_loc l
CROSS JOIN unnest(ARRAY['appointments','branches','customers','medicines','people','pharmacy','prescriptions','settings']) AS m(key);

-- 12,000 branch roles: every branch gets one, 2,000 get a second with a different name.
INSERT INTO roles (name, slug, description, icon, scope, location_id, created_at, updated_at)
SELECT r.names[s.i],
       lower(replace(r.names[s.i], ' ', '-')) || '-prac-' || s.g,
       r.names[s.i] || ' at branch ' || s.loc_rn,
       r.icons[s.i], 'branch', l.id, now(), now()
FROM (SELECT g, 1 + (g - 1) % 10000 AS loc_rn, 1 + (((g - 1) % 10000) + (g - 1) / 10000) % 6 AS i
      FROM generate_series(1, 12000) g) s
JOIN t_loc l ON l.rn = s.loc_rn
CROSS JOIN (SELECT ARRAY['Receptionist','Pharmacist','Nurse','Branch Manager','Billing Executive','Lab Technician'] AS names,
                   ARRAY['ti ti-headset','ti ti-pill','ti ti-heartbeat','ti ti-briefcase','ti ti-receipt','ti ti-microscope'] AS icons) r
ORDER BY s.g;

CREATE TEMP TABLE t_role AS
SELECT id, location_id, row_number() OVER (ORDER BY id)::int AS rn FROM roles WHERE slug LIKE '%-prac-%';
CREATE INDEX ON t_role (location_id);
CREATE UNIQUE INDEX ON t_role (rn);

INSERT INTO role_capabilities (role_id, capability, created_at, updated_at)
SELECT r.id, c.cap, now(), now()
FROM t_role r
CROSS JOIN unnest(ARRAY['appointments.book','appointments.cancel','appointments.doctors','appointments.queue',
                        'appointments.schedule','appointments.view','branches.view','customers.create',
                        'customers.delete','customers.edit','customers.view','people.create','people.delete',
                        'people.edit','people.roles','people.view']) AS c(cap)
WHERE c.cap LIKE '%.view' OR random() < 0.45;

-- 10,000 staff, one per branch. Every practice user's password is "password".
INSERT INTO users (name, email, password, email_verified_at, is_active, last_login_at, last_login_ip,
                   created_at, updated_at, role, location_id, role_id)
SELECT s.first || ' ' || s.last,
       lower(s.first || '.' || s.last || s.g) || '@practice.test',
       '$2y$12$sl3dR3hS.4tmJhjtGHXT8uv8qG0yAykUV9yc34t/L6OVEbE5xJN82',
       s.created_at,
       random() > 0.05,
       CASE WHEN random() < 0.8 THEN now()::timestamp - random() * interval '60 days' END,
       '192.168.' || pg_temp.rand_int(0, 5) || '.' || pg_temp.rand_int(2, 254),
       s.created_at, s.created_at, 'staff', r.location_id, r.id
FROM (SELECT g,
             CASE WHEN random() < 0.5 THEN pg_temp.pick(w.male) ELSE pg_temp.pick(w.female) END AS first,
             pg_temp.pick(w.last) AS last,
             now()::timestamp - interval '100 days' - random() * interval '400 days' AS created_at
      FROM generate_series(1, 10000) g CROSS JOIN t_words w OFFSET 0) s
JOIN t_role r ON r.rn = s.g
ORDER BY s.g;

CREATE TEMP TABLE t_user AS
SELECT id, name, location_id, role_id, row_number() OVER (ORDER BY id)::int AS rn
FROM users WHERE email LIKE '%@practice.test' AND userable_id IS NULL;
CREATE UNIQUE INDEX ON t_user (rn);
CREATE INDEX ON t_user (location_id);

-- Primary membership for everyone, plus a second branch for 30% of staff.
INSERT INTO branch_users (user_id, location_id, role_id, is_primary, created_at, updated_at)
SELECT id, location_id, role_id, true, now(), now() FROM t_user
UNION ALL
SELECT u.id, l.id, r.id, false, now(), now()
FROM t_user u
JOIN t_loc l ON l.rn = 1 + ((u.rn * 13 + 7) % 10000)
JOIN LATERAL (SELECT id FROM t_role WHERE location_id = l.id ORDER BY id LIMIT 1) r ON true
WHERE u.rn % 10 < 3 AND l.id <> u.location_id;

-- =============================================================================
--  2. Patients and doctors
-- =============================================================================

INSERT INTO customers (name, phone, email, date_of_birth, gender, address, city, district, state, country,
                       pincode, notes, is_active, registered_location_id, code, created_at, updated_at, deleted_at)
SELECT s.first || ' ' || s.last,
       pg_temp.phone('7', s.g),
       CASE WHEN random() < 0.6
            THEN lower(s.first || '.' || s.last || s.g) || '@' || pg_temp.pick(ARRAY['gmail.com','yahoo.in','outlook.com','rediffmail.com'])
       END,
       current_date - pg_temp.rand_int(0, 85 * 365),
       s.gender,
       pg_temp.rand_int(1, 999) || ', ' || pg_temp.pick(w.streets),
       c.city, c.district, c.state, 'India',
       (c.pin * 1000 + pg_temp.rand_int(1, 99))::text,
       CASE WHEN random() < 0.08
            THEN pg_temp.pick(ARRAY['Allergic to penicillin','Diabetic — check sugar before procedures','Prefers Hindi',
                                    'Hearing impaired, speak slowly','Referred by Dr. Rao','Senior citizen discount'])
       END,
       random() > 0.03,
       l.id,
       'P-' || lpad((m.max_code + s.g)::text, 5, '0'),
       s.created_at, s.created_at,
       CASE WHEN random() < 0.02 THEN s.created_at + interval '30 days' END
FROM (SELECT g, pg_temp.rand_int(1, 30) AS ck, pg_temp.rand_int(1, 10000) AS loc_rn,
             x.gender,
             CASE WHEN x.gender = 'female' THEN pg_temp.pick(w.female) ELSE pg_temp.pick(w.male) END AS first,
             pg_temp.pick(w.last) AS last,
             now()::timestamp - interval '40 days' - random() * interval '800 days' AS created_at
      FROM generate_series(1, 50000) g
      CROSS JOIN t_words w
      CROSS JOIN LATERAL (SELECT CASE WHEN r < 0.48 THEN 'male' WHEN r < 0.96 THEN 'female' WHEN r < 0.98 THEN 'other' END AS gender
                          FROM (SELECT random() + g * 0 AS r) q) x
      OFFSET 0) s
JOIN t_city c ON c.k = s.ck
JOIN t_loc l ON l.rn = s.loc_rn
CROSS JOIN t_words w
CROSS JOIN (SELECT coalesce(max(NULLIF(regexp_replace(code, '\D', '', 'g'), '')::bigint), 0) AS max_code
            FROM customers WHERE code LIKE 'P-%') m
ORDER BY s.created_at
ON CONFLICT DO NOTHING;

CREATE TEMP TABLE t_cust AS
SELECT id, name, phone, code, created_at, row_number() OVER (ORDER BY id)::int AS rn
FROM customers WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX ON t_cust (rn);

INSERT INTO doctors (name, code, specialisation, registration_no, phone, email, default_consultation_fee,
                     is_active, qualifications, created_at, updated_at)
SELECT 'Dr. ' || s.first || ' ' || s.last,
       'DOC-P' || lpad(s.g::text, 5, '0'),
       sp.name,
       pg_temp.pick(ARRAY['MCI','DMC','MMC','KMC','TNMC','UPMC']) || '-' || (100000 + s.g),
       pg_temp.phone('6', s.g),
       'dr.' || lower(s.first || '.' || s.last || s.g) || '@practice.test',
       round((sp.fee_lo + random() * (sp.fee_hi - sp.fee_lo))::numeric / 50) * 50,
       random() > 0.05,
       sp.quals,
       s.created_at, s.created_at
FROM (SELECT g, pg_temp.rand_int(1, 20) AS spk,
             CASE WHEN random() < 0.55 THEN pg_temp.pick(w.male) ELSE pg_temp.pick(w.female) END AS first,
             pg_temp.pick(w.last) AS last,
             now()::timestamp - interval '520 days' - random() * interval '300 days' AS created_at
      FROM generate_series(1, 10000) g CROSS JOIN t_words w OFFSET 0) s
JOIN t_spec sp ON sp.k = s.spk
ORDER BY s.g;

CREATE TEMP TABLE t_doc AS
SELECT id, name, row_number() OVER (ORDER BY id)::int AS rn FROM doctors WHERE code LIKE 'DOC-P%';
CREATE UNIQUE INDEX ON t_doc (rn);

-- 3,000 doctors can sign in.
INSERT INTO users (name, email, password, email_verified_at, userable_type, userable_id, is_active,
                   created_at, updated_at, role, role_id)
SELECT d.name, 'doctor' || d.rn || '@practice.test',
       '$2y$12$sl3dR3hS.4tmJhjtGHXT8uv8qG0yAykUV9yc34t/L6OVEbE5xJN82',
       now(), 'App\Models\Tenant\Doctor', d.id, true, now(), now(), 'staff',
       (SELECT id FROM roles WHERE slug = 'doctor')
FROM t_doc d WHERE d.rn <= 3000;

-- Each doctor practises at two branches.
INSERT INTO doctor_locations (doctor_id, location_id, is_active, created_at, updated_at)
SELECT d.id, l.id, true, now(), now()
FROM t_doc d
CROSS JOIN (VALUES (0), (1)) AS v(slot)
JOIN t_loc l ON l.rn = 1 + ((d.rn * 7 + v.slot * 4999) % 10000);

-- Three sittings per doctor on three different weekdays: morning and evening at
-- the first branch, a visiting OPD at the second.
INSERT INTO doctor_schedules (doctor_id, location_id, name, weekday, starts_at, ends_at, slot_minutes,
                              max_walkins, is_active, effective_from, created_at, updated_at)
SELECT x.id, l.id, x.name, (x.rn + x.s * 2) % 7, x.st, x.st + x.hrs * interval '1 hour', x.slot,
       x.walkins, random() > 0.03, date_trunc('week', current_date - 490)::date, now(), now()
FROM (SELECT d.id, d.rn, v.s,
             (ARRAY['Morning OPD','Evening OPD','Visiting OPD'])[v.s + 1] AS name,
             (ARRAY[time '09:00', time '17:00', time '11:00'])[v.s + 1] + pg_temp.rand_int(0, 2) * interval '30 minutes' AS st,
             pg_temp.rand_int(3, 4) AS hrs,
             (ARRAY[10, 15, 15, 20, 30])[pg_temp.rand_int(1, 5)] AS slot,
             CASE WHEN random() < 0.7 THEN pg_temp.rand_int(5, 20) END AS walkins
      FROM t_doc d CROSS JOIN generate_series(0, 2) AS v(s) OFFSET 0) x
JOIN t_loc l ON l.rn = 1 + ((x.rn * 7 + CASE WHEN x.s = 2 THEN 4999 ELSE 0 END) % 10000)
ORDER BY x.id, x.s;

CREATE TEMP TABLE t_sched AS
SELECT sc.id, sc.doctor_id, sc.location_id, sc.weekday, sc.starts_at, sc.ends_at, sc.slot_minutes,
       (row_number() OVER (PARTITION BY sc.doctor_id ORDER BY sc.id) - 1)::int AS s,
       row_number() OVER (ORDER BY sc.id)::int AS rn
FROM doctor_schedules sc JOIN t_doc d ON d.id = sc.doctor_id;
CREATE INDEX ON t_sched (doctor_id, s);
CREATE UNIQUE INDEX ON t_sched (rn);

INSERT INTO doctor_schedule_exceptions (doctor_id, doctor_schedule_id, location_id, date, type, starts_at,
                                        ends_at, slot_minutes, reason, created_by, created_at, updated_at)
SELECT sc.doctor_id, sc.id, sc.location_id, x.date, x.type,
       CASE x.type WHEN 'changed_hours' THEN sc.starts_at + interval '1 hour' WHEN 'extra_session' THEN time '19:00' END,
       CASE x.type WHEN 'changed_hours' THEN sc.ends_at WHEN 'extra_session' THEN time '21:00' END,
       CASE WHEN x.type <> 'unavailable' THEN sc.slot_minutes END,
       CASE x.type
           WHEN 'unavailable' THEN pg_temp.pick(ARRAY['Personal leave','Medical conference','Emergency surgery','Public holiday','Family function'])
           WHEN 'changed_hours' THEN pg_temp.pick(ARRAY['Late start — ward rounds','Hospital board meeting','Teaching session'])
           ELSE pg_temp.pick(ARRAY['Extra OPD for health camp','Covering for colleague','Festival rush'])
       END,
       u.id, x.date - 3 + time '12:00', x.date - 3 + time '12:00'
FROM (SELECT g, pg_temp.rand_int(1, 30000) AS sched_rn,
             pg_temp.pick(ARRAY['unavailable','unavailable','changed_hours','extra_session']) AS type,
             pg_temp.rand_int(0, 85) AS week
      FROM generate_series(1, 12000) g OFFSET 0) x0
JOIN t_sched sc ON sc.rn = x0.sched_rn
CROSS JOIN LATERAL (SELECT x0.type, (date_trunc('week', current_date - 490)::date + 7 * x0.week + sc.weekday) AS date) x
JOIN t_user u ON u.rn = 1 + (x0.g % 10000);

-- =============================================================================
--  3. Appointments (150,000) and consultations
--
--  15 per doctor across ~86 weeks. Appointment j lands in week f(j / 3) on the
--  weekday of the doctor's sitting j % 3, so no doctor ever has two on a date.
-- =============================================================================

CREATE TEMP TABLE t_appt AS
SELECT p.*,
       CASE
           WHEN p.appointment_date < current_date THEN
               CASE WHEN p.r_status < 0.76 THEN 'completed' WHEN p.r_status < 0.88 THEN 'cancelled' ELSE 'no_show' END
           WHEN p.appointment_date = current_date THEN
               (ARRAY['booked','checked_in','in_consultation','completed'])[1 + floor(p.r_status * 4)::int]
           ELSE CASE WHEN p.r_status < 0.9 THEN 'booked' ELSE 'cancelled' END
       END AS status,
       CASE WHEN NOT p.is_walk_in
            THEN p.starts_at + p.slot_minutes * floor(p.r_slot * (extract(epoch FROM p.ends_at - p.starts_at) / 60 / p.slot_minutes)) * interval '1 minute'
       END AS slot_at
FROM (SELECT d.id AS doctor_id, sc.id AS schedule_id, sc.location_id, sc.starts_at, sc.ends_at, sc.slot_minutes,
             b.base + 7 * (((j.j / 3) * 17 + d.rn * 5) % 86) + sc.weekday AS appointment_date,
             random() < 0.3 AS is_walk_in,
             random() AS r_status, random() AS r_slot, random() AS r_time,
             1 + floor(power(random(), 1.6) * b.n_cust)::int AS cust_rn,   -- older patients come back more
             pg_temp.rand_int(1, 40) AS token_no,
             pg_temp.rand_int(4, 45) AS wait_min,
             pg_temp.rand_int(5, 25) AS consult_min,
             pg_temp.rand_int(0, 14) AS booked_ahead
      FROM t_doc d
      CROSS JOIN generate_series(0, 14) AS j(j)
      JOIN t_sched sc ON sc.doctor_id = d.id AND sc.s = j.j % 3
      CROSS JOIN (SELECT date_trunc('week', current_date - 490)::date AS base,
                         (SELECT count(*) FROM t_cust) AS n_cust) b
      OFFSET 0) p;

INSERT INTO appointments (customer_id, doctor_id, location_id, doctor_schedule_id, appointment_date, type, status,
                          slot_at, token_no, checked_in_at, started_at, completed_at, cancellation_reason, notes,
                          created_at, updated_at)
SELECT c.id, a.doctor_id, a.location_id, a.schedule_id, a.appointment_date,
       CASE WHEN a.is_walk_in THEN 'walk_in' ELSE 'booked' END,
       a.status, a.slot_at, a.token_no,
       a.checked_in_at, a.started_at, a.completed_at,
       CASE WHEN a.status = 'cancelled'
            THEN pg_temp.pick(ARRAY['Patient requested','Doctor unavailable','Rescheduled by patient','Could not reach patient','Duplicate booking'])
       END,
       CASE WHEN random() < 0.05
            THEN pg_temp.pick(ARRAY['Follow-up visit','Bring previous reports','Wheelchair needed','Fasting sample required','Insurance patient'])
       END,
       a.created_at,
       coalesce(a.completed_at, a.started_at, a.checked_in_at, a.created_at)
FROM (SELECT t.*,
             CASE WHEN t.status IN ('checked_in','in_consultation','completed') THEN t.arrive END AS checked_in_at,
             CASE WHEN t.status IN ('in_consultation','completed') THEN t.arrive + t.wait_min * interval '1 minute' END AS started_at,
             CASE WHEN t.status = 'completed' THEN t.arrive + (t.wait_min + t.consult_min) * interval '1 minute' END AS completed_at,
             CASE WHEN t.is_walk_in THEN t.arrive
                  ELSE least(now()::timestamp, (t.appointment_date - t.booked_ahead) + time '09:00' + t.r_time * interval '10 hours')
             END AS created_at
      FROM (SELECT t.*,
                   t.appointment_date + coalesce(t.slot_at, t.starts_at + t.r_time * (t.ends_at - t.starts_at)) - interval '10 minutes' AS arrive
            FROM t_appt t) t) a
JOIN t_cust c ON c.rn = a.cust_rn
ORDER BY a.appointment_date, a.slot_at NULLS LAST;

INSERT INTO consultations (appointment_id, customer_id, doctor_id, chief_complaint, diagnoses, vitals, prescription,
                           investigations, advice, follow_up_days, created_at, updated_at)
SELECT x.id, x.customer_id, x.doctor_id, dx.complaint,
       jsonb_build_array(dx.diagnosis),
       jsonb_build_object('bp_systolic', pg_temp.rand_int(100, 160), 'bp_diastolic', pg_temp.rand_int(60, 100),
                          'pulse', pg_temp.rand_int(60, 110), 'temperature', round((36.3 + random() * 2.4)::numeric, 1),
                          'spo2', pg_temp.rand_int(93, 100), 'weight', round((12 + random() * 80)::numeric, 1),
                          'height', pg_temp.rand_int(95, 190)),
       -- The aggregate must read a column of its own FROM (dw), or Postgres
       -- files it under the outer query and demands a GROUP BY there.
       (SELECT jsonb_agg(jsonb_build_object('drug', pg_temp.pick(dw.drugs),
                                            'dose', pg_temp.pick(ARRAY['1','1','2','1/2']),
                                            'frequency', pg_temp.pick(ARRAY['1','2','2','3']),
                                            'duration', pg_temp.pick(ARRAY['3','5','5','7','10','14','30'])))
        FROM generate_series(1, x.n_drugs) CROSS JOIN t_words dw),
       CASE WHEN x.r_inv < 0.4
            THEN jsonb_build_array(jsonb_build_object('test', dx.test, 'notes', pg_temp.pick(ARRAY['Fasting','Urgent','Before next visit','Routine'])))
       END,
       pg_temp.pick(ARRAY['Plenty of fluids and rest','Avoid oily and spicy food','Walk 30 minutes daily',
                          'Reduce salt intake','Complete the full antibiotic course','Review with reports']),
       CASE WHEN random() < 0.6 THEN (ARRAY[3, 5, 7, 7, 14, 30, 90])[pg_temp.rand_int(1, 7)] END,
       x.completed_at, x.completed_at
FROM (SELECT a.id, a.customer_id, a.doctor_id, a.completed_at,
             pg_temp.rand_int(1, 20) AS dx_k, pg_temp.rand_int(1, 3) AS n_drugs, random() AS r_inv
      FROM appointments a
      CROSS JOIN t_mark m
      WHERE a.id > m.appointment_max AND a.status = 'completed'
      OFFSET 0) x
JOIN t_dx dx ON dx.k = x.dx_k
CROSS JOIN t_words w
ORDER BY x.completed_at;

-- =============================================================================
--  4. Pharmacy master data
-- =============================================================================

-- 12,000 medicines. Row g takes generic g % n, manufacturer (g / n) % 30 and brand
-- suffix g / (n * 30) — one combination per row, so the identity index never trips.
INSERT INTO medicines (medicine_code, generic_name, brand_name, strength, dosage_form, route, base_unit, pack_size,
                       manufacturer, category, schedule, prescription_required, is_active, created_at, updated_at)
SELECT 'MED-' || lpad((x.g + 1)::text, 5, '0'),
       gn.generic,
       trim(gn.root || ' ' || sfx.list[1 + x.g / (x.n * 30)]),
       gn.strengths[1 + (x.g / 7) % array_length(gn.strengths, 1)],
       gn.form, gn.route, gn.unit, gn.pack,
       mf.list[1 + (x.g / x.n) % 30],
       gn.category, gn.schedule, gn.schedule <> 'OTC',
       random() > 0.04,
       now()::timestamp - interval '500 days' - random() * interval '300 days',
       now()::timestamp - random() * interval '200 days'
FROM (SELECT g, (SELECT count(*) FROM t_generic)::int AS n FROM generate_series(0, 11999) g) x
JOIN t_generic gn ON gn.k = 1 + x.g % x.n
CROSS JOIN (SELECT ARRAY['', 'Forte', 'Plus', 'DS', 'SR', 'XL', 'MR', 'LS', 'Kid', 'Max'] AS list) sfx
CROSS JOIN (SELECT ARRAY['Sun Pharma','Cipla','Dr. Reddy''s','Lupin','Zydus Lifesciences','Torrent','Alkem','Mankind',
                         'Glenmark','Intas','Abbott India','Micro Labs','Macleods','Aristo','Ipca','Biocon','Emcure',
                         'Wockhardt','Ajanta','FDC','Unichem','Indoco','Alembic','Blue Cross','Hetero','Natco','Eris',
                         'JB Chemicals','Piramal','Mepromax'] AS list) mf
ORDER BY x.g;

CREATE TEMP TABLE t_med AS
SELECT m.id, m.pack_size, gn.price_lo, gn.price_hi, row_number() OVER (ORDER BY m.id)::int AS rn
FROM medicines m
JOIN t_generic gn ON gn.generic = m.generic_name AND gn.form = m.dosage_form
WHERE m.medicine_code LIKE 'MED-%';
CREATE UNIQUE INDEX ON t_med (rn);

INSERT INTO suppliers (name, code, gstin, drug_license_no, drug_license_expiry_date, contact_person, phone, email,
                       address, is_active, created_at, updated_at)
SELECT s.name || ' ' || c.city,
       'SUP-' || lpad(s.g::text, 5, '0'),
       lpad(c.k::text, 2, '0') || translate(upper(substr(md5(s.g::text), 1, 5)), '0123456789', 'ABCDEFGHIJ')
           || lpad((s.g % 10000)::text, 4, '0') || chr(65 + s.g / 10000) || '1Z' || (s.g % 10),
       'DL-' || upper(left(c.state, 2)) || '-21B-' || (200000 + s.g),
       current_date + pg_temp.rand_int(-120, 1500),
       pg_temp.pick(w.male) || ' ' || pg_temp.pick(w.last),
       pg_temp.phone('8', s.g),
       'orders' || s.g || '@supplier.test',
       pg_temp.rand_int(1, 200) || ', ' || pg_temp.pick(w.streets) || ', ' || c.city,
       random() > 0.05,
       now()::timestamp - interval '500 days' - random() * interval '300 days', now()
FROM (SELECT g, pg_temp.rand_int(1, 30) AS ck,
             pg_temp.pick(ARRAY['Shree','Sai','Om','New','Jai','Ganesh','Balaji','Krishna','Metro','City','Apex',
                                'Lifeline','Medi','Care','Wellness','Sanjivani']) || ' ' ||
             pg_temp.pick(ARRAY['Pharma','Medical Agencies','Drug House','Distributors','Healthcare','Medicos',
                                'Pharma Traders','Surgicals']) AS name
      FROM generate_series(1, 10000) g OFFSET 0) s
JOIN t_city c ON c.k = s.ck
CROSS JOIN t_words w
ORDER BY s.g;

CREATE TEMP TABLE t_sup AS
SELECT id, row_number() OVER (ORDER BY id)::int AS rn FROM suppliers WHERE code LIKE 'SUP-%';
CREATE UNIQUE INDEX ON t_sup (rn);

-- One default pharmacy per branch, run by that branch's staff member.
INSERT INTO pharmacy_stores (location_id, name, code, store_type, is_default, pharmacist_user_id, address, phone,
                             drug_license_no, drug_license_expiry_date, is_active, created_at, updated_at)
SELECT loc.id, loc.name || ' Pharmacy', 'PS-' || lpad(l.rn::text, 5, '0'),
       pg_temp.pick(ARRAY['hospital_pharmacy','opd_counter','opd_counter','retail','retail','ipd_pharmacy','emergency','central']),
       true, u.id, loc.address, loc.phone,
       'DL-' || upper(left(loc.state, 2)) || '-20C-' || (300000 + l.rn),
       current_date + pg_temp.rand_int(-60, 1500),
       loc.is_active, loc.created_at, loc.created_at
FROM t_loc l
JOIN locations loc ON loc.id = l.id
JOIN t_user u ON u.location_id = l.id
ORDER BY l.rn;

CREATE TEMP TABLE t_store AS
SELECT ps.id, ps.location_id, ps.pharmacist_user_id AS pharmacist_id, u.name AS pharmacist_name,
       row_number() OVER (ORDER BY ps.id)::int AS rn
FROM pharmacy_stores ps JOIN users u ON u.id = ps.pharmacist_user_id
WHERE ps.code LIKE 'PS-%';
CREATE UNIQUE INDEX ON t_store (rn);

-- Five medicines per store; slot k of store s is medicine 1 + (37s + 2411k) mod 12000.
INSERT INTO store_medicines (pharmacy_store_id, medicine_id, reorder_level, minimum_stock_level, maximum_stock_level,
                             is_active, preferred_supplier_id, created_at, updated_at)
SELECT x.store_id, m.id, x.lvl * 2, x.lvl, x.lvl * 10, random() > 0.03, sup.id, now(), now()
FROM (SELECT s.id AS store_id, s.rn, k.k, pg_temp.rand_int(10, 100) AS lvl, pg_temp.rand_int(1, 10000) AS sup_rn
      FROM t_store s CROSS JOIN generate_series(0, 4) AS k(k) OFFSET 0) x
JOIN t_med m ON m.rn = 1 + ((x.rn * 37 + x.k * 2411) % 12000)
JOIN t_sup sup ON sup.rn = x.sup_rn;

-- =============================================================================
--  5. Stock: goods received → batches → ledger
-- =============================================================================

CREATE TEMP TABLE t_inw AS
SELECT x.g, s.id AS store_id, s.rn AS store_rn, s.location_id, (x.g - 1) / 10000 AS round, x.inward_type,
       CASE WHEN x.inward_type = 'purchase' THEN sup.id END AS supplier_id,
       x.received_date, s.pharmacist_id AS user_id, s.pharmacist_name AS user_name,
       x.received_date + time '10:00' + x.r_time * interval '8 hours' AS created_at
FROM (SELECT g, 1 + (g - 1) % 10000 AS store_rn,
             CASE WHEN r < 0.85 THEN 'purchase' WHEN r < 0.95 THEN 'opening_balance' ELSE 'return_from_patient' END AS inward_type,
             current_date - pg_temp.rand_int(100, 480) AS received_date,
             pg_temp.rand_int(1, 10000) AS sup_rn, r_time
      FROM (SELECT g, random() AS r, random() AS r_time FROM generate_series(1, 20000) g OFFSET 0) q
      OFFSET 0) x
JOIN t_store s ON s.rn = x.store_rn
JOIN t_sup sup ON sup.rn = x.sup_rn;

INSERT INTO stock_inwards (pharmacy_store_id, location_id, supplier_id, inward_type, supplier_invoice_no,
                           supplier_invoice_date, received_date, total_amount, status, idempotency_key,
                           created_by, created_by_name, created_at, updated_at)
SELECT store_id, location_id, supplier_id, inward_type,
       CASE WHEN supplier_id IS NOT NULL THEN 'INV/' || to_char(received_date, 'YYMM') || '/' || lpad(g::text, 6, '0') END,
       CASE WHEN supplier_id IS NOT NULL THEN received_date - pg_temp.rand_int(0, 5) END,
       received_date, 0, 'posted', 'seed-grn-' || g, user_id, user_name, created_at, created_at
FROM t_inw
ORDER BY created_at;   -- GRN numbers come out in date order

ALTER TABLE t_inw ADD inward_id bigint, ADD inward_number text;
UPDATE t_inw t SET inward_id = i.id, inward_number = i.inward_number
FROM stock_inwards i WHERE i.idempotency_key = 'seed-grn-' || t.g;

-- Three lines per receipt, drawn from the store's own five medicines, each its own batch.
-- Quantities are units (quantity + free_quantity is what goes on the shelf).
CREATE TEMP TABLE t_line AS
SELECT x.*,
       x.qty + x.free AS units,
       upper(substr(md5(x.ln::text), 1, 3)) || lpad(x.ln::text, 6, '0') AS batch_number,
       round(x.mrp * (0.86 + x.r_sell * 0.14)::numeric, 2) AS selling,
       round(x.mrp * (0.55 + x.r_buy * 0.20)::numeric, 2) AS purchase,
       x.received_date - x.mfg_age AS mfg,
       x.received_date - x.mfg_age + x.shelf AS expiry,
       floor((x.qty + x.free) * 0.20)::int AS d1,
       floor((x.qty + x.free) * 0.15)::int AS d2,
       x.created_at + pg_temp.rand_int(3, 28) * interval '1 day' + random() * interval '8 hours' AS d1_at,
       x.created_at + pg_temp.rand_int(30, 58) * interval '1 day' + random() * interval '8 hours' AS d2_at,
       NULL::bigint AS batch_id,
       NULL::bigint AS item_id
FROM (SELECT row_number() OVER (ORDER BY i.inward_id, k.k)::int AS ln,
             i.inward_id, i.inward_number, i.store_id, i.store_rn, i.location_id, i.supplier_id, i.inward_type,
             i.received_date, i.created_at, i.user_id, i.user_name,
             m.id AS medicine_id, m.pack_size,
             m.pack_size * pg_temp.rand_int(60 / m.pack_size + 1, 60 / m.pack_size + 40) AS qty,
             CASE WHEN random() < 0.3 THEN m.pack_size * pg_temp.rand_int(1, 3) ELSE 0 END AS free,
             round((m.price_lo + random() * (m.price_hi - m.price_lo))::numeric, 2) AS mrp,
             random() AS r_sell, random() AS r_buy,
             pg_temp.rand_int(20, 200) AS mfg_age,
             (ARRAY[365, 540, 730, 730, 1095])[pg_temp.rand_int(1, 5)] AS shelf
      FROM t_inw i
      CROSS JOIN generate_series(0, 2) AS k(k)
      JOIN t_med m ON m.rn = 1 + ((i.store_rn * 37 + ((i.round * 2 + k.k) % 5) * 2411) % 12000)
      OFFSET 0) x;

-- Batches start empty; the ledger below fills them.
INSERT INTO medicine_batches (pharmacy_store_id, medicine_id, supplier_id, batch_number, expiry_date, manufacture_date,
                              purchase_price, selling_price, mrp, quantity_received, quantity_available, status,
                              received_date, created_at, updated_at)
SELECT store_id, medicine_id, supplier_id, batch_number, expiry, mfg, purchase, selling, mrp, units, 0, 'active',
       received_date, created_at, created_at
FROM t_line ORDER BY ln;

UPDATE t_line l SET batch_id = b.id
FROM medicine_batches b
WHERE b.pharmacy_store_id = l.store_id AND b.medicine_id = l.medicine_id AND lower(b.batch_number) = lower(l.batch_number)
  AND b.deleted_at IS NULL;

INSERT INTO stock_inward_items (stock_inward_id, medicine_id, medicine_batch_id, batch_number, expiry_date,
                                manufacture_date, pack_size, quantity, free_quantity, purchase_price, selling_price,
                                mrp, line_total, created_at, updated_at)
SELECT inward_id, medicine_id, batch_id, batch_number, expiry, mfg, pack_size, qty, free, purchase, selling, mrp,
       round(qty * purchase, 2), created_at, created_at
FROM t_line ORDER BY ln;

UPDATE t_line l SET item_id = i.id FROM stock_inward_items i WHERE i.medicine_batch_id = l.batch_id;
CREATE INDEX ON t_line (batch_id);

UPDATE medicine_batches b SET stock_inward_item_id = l.item_id FROM t_line l WHERE b.id = l.batch_id;

UPDATE stock_inwards i SET total_amount = t.total
FROM (SELECT inward_id, sum(round(qty * purchase, 2)) AS total FROM t_line GROUP BY inward_id) t
WHERE i.id = t.inward_id;

-- 12,000 adjustments: every fifth batch loses (or, for a recount, gains) ~5%.
CREATE TEMP TABLE t_adj AS
SELECT x.*,
       CASE WHEN x.reason_code = 'count_correction' AND x.r < 0.4 THEN 'increase' ELSE 'decrease' END AS direction,
       CASE WHEN x.reason_code = 'count_correction' AND x.r < 0.4 THEN 'adjustment_increase'
            WHEN x.reason_code = 'damage' THEN 'damage'
            WHEN x.reason_code = 'expiry_writeoff' THEN 'expiry_writeoff'
            ELSE 'adjustment_decrease'
       END AS movement_type,
       CASE x.reason_code
           WHEN 'damage' THEN pg_temp.pick(ARRAY['Strips crushed in transit','Bottle broken while shelving','Water damage in storeroom'])
           WHEN 'expiry_writeoff' THEN pg_temp.pick(ARRAY['Near-expiry stock pulled from shelf','Expired stock written off'])
           WHEN 'count_correction' THEN pg_temp.pick(ARRAY['Monthly physical count','Cycle count variance','Audit recount'])
           WHEN 'loss' THEN pg_temp.pick(ARRAY['Missing after stock take','Pilferage suspected'])
           ELSE pg_temp.pick(ARRAY['Sample given to doctor','Used for patient demonstration'])
       END AS reason
FROM (SELECT l.batch_id, l.store_id, l.location_id, l.medicine_id, l.purchase,
             greatest(1, floor(l.units * 0.05))::int AS qty,
             pg_temp.pick(ARRAY['damage','damage','expiry_writeoff','count_correction','count_correction','loss','other']) AS reason_code,
             random() AS r,
             l.created_at + pg_temp.rand_int(61, 74) * interval '1 day' + random() * interval '8 hours' AS at,
             l.user_id, l.user_name
      FROM t_line l WHERE l.ln % 5 = 0 OFFSET 0) x;

INSERT INTO stock_adjustments (pharmacy_store_id, location_id, medicine_id, medicine_batch_id, direction, quantity,
                               reason_code, reason, idempotency_key, created_by, created_by_name, created_at, updated_at)
SELECT store_id, location_id, medicine_id, batch_id, direction, qty, reason_code, reason,
       'seed-adj-' || batch_id, user_id, user_name, at, at
FROM t_adj ORDER BY at;

ALTER TABLE t_adj ADD adjustment_id bigint;
UPDATE t_adj t SET adjustment_id = a.id FROM stock_adjustments a WHERE a.idempotency_key = 'seed-adj-' || t.batch_id;

-- 12,000 transfers: every fifth batch (offset 2) sends ~10% to another store,
-- which receives it as a new batch carrying the same batch number.
CREATE TEMP TABLE t_trf AS
SELECT l.batch_id AS source_batch_id, l.store_id AS from_store_id, l.location_id AS from_location_id,
       s.id AS to_store_id, s.location_id AS to_location_id,
       l.medicine_id, l.supplier_id, l.batch_number, l.expiry, l.mfg, l.purchase, l.selling, l.mrp,
       greatest(1, floor(l.units * 0.10))::int AS qty,
       l.created_at + pg_temp.rand_int(76, 89) * interval '1 day' + random() * interval '8 hours' AS at,
       l.user_id, l.user_name
FROM t_line l
JOIN t_store s ON s.rn = 1 + ((l.store_rn + l.ln % 50) % 10000)
WHERE l.ln % 5 = 2;

INSERT INTO store_medicines (pharmacy_store_id, medicine_id, reorder_level, minimum_stock_level, maximum_stock_level,
                             created_at, updated_at)
SELECT DISTINCT to_store_id, medicine_id, 20, 10, 100, now()::timestamp, now()::timestamp FROM t_trf
ON CONFLICT DO NOTHING;

INSERT INTO medicine_batches (pharmacy_store_id, medicine_id, supplier_id, batch_number, expiry_date, manufacture_date,
                              purchase_price, selling_price, mrp, quantity_received, quantity_available, status,
                              received_date, created_at, updated_at)
SELECT to_store_id, medicine_id, supplier_id, batch_number, expiry, mfg, purchase, selling, mrp, qty, 0, 'active',
       at::date, at, at
FROM t_trf ORDER BY t_trf.at;

INSERT INTO stock_transfers (from_store_id, to_store_id, status, notes, idempotency_key, created_by, created_by_name,
                             created_at, updated_at)
SELECT from_store_id, to_store_id, 'completed',
       CASE WHEN random() < 0.3 THEN pg_temp.pick(ARRAY['Stock rebalancing','Urgent requirement at branch','Near-expiry — move to busier store']) END,
       'seed-trf-' || source_batch_id, user_id, user_name, at, at
FROM t_trf ORDER BY at;

ALTER TABLE t_trf ADD transfer_id bigint, ADD transfer_number text, ADD dest_batch_id bigint;
UPDATE t_trf t SET transfer_id = st.id, transfer_number = st.transfer_number
FROM stock_transfers st WHERE st.idempotency_key = 'seed-trf-' || t.source_batch_id;
UPDATE t_trf t SET dest_batch_id = b.id
FROM medicine_batches b
WHERE b.pharmacy_store_id = t.to_store_id AND b.medicine_id = t.medicine_id
  AND lower(b.batch_number) = lower(t.batch_number) AND b.deleted_at IS NULL;

INSERT INTO stock_transfer_items (stock_transfer_id, medicine_id, source_batch_id, destination_batch_id, quantity,
                                  created_at, updated_at)
SELECT transfer_id, medicine_id, source_batch_id, dest_batch_id, qty, at, at FROM t_trf ORDER BY at;

-- The ledger. Every stock change above becomes one row; running sums per batch
-- give quantity_before / quantity_after, exactly as StockMovementService writes them.
CREATE TEMP TABLE t_mv AS
SELECT batch_id, store_id, location_id, medicine_id, 1 AS seq, inward_type AS movement_type, units AS qty,
       purchase AS unit_cost, 'stock_inward_item' AS ref_type, item_id AS ref_id, NULL AS reason,
       inward_number AS notes, user_id, user_name, created_at AS at
FROM t_line
UNION ALL
SELECT batch_id, store_id, location_id, medicine_id, 2, 'dispensing', -d1, purchase, NULL, NULL, NULL,
       'OPD counter', user_id, user_name, d1_at
FROM t_line
UNION ALL
SELECT batch_id, store_id, location_id, medicine_id, 3, 'dispensing', -d2, purchase, NULL, NULL, NULL,
       'OPD counter', user_id, user_name, d2_at
FROM t_line
UNION ALL
SELECT batch_id, store_id, location_id, medicine_id, 4, movement_type,
       CASE WHEN direction = 'increase' THEN qty ELSE -qty END, purchase, 'stock_adjustment', adjustment_id,
       reason, NULL, user_id, user_name, at
FROM t_adj
UNION ALL
SELECT source_batch_id, from_store_id, from_location_id, medicine_id, 5, 'transfer_out', -qty, purchase,
       'stock_transfer', transfer_id, NULL, transfer_number, user_id, user_name, at
FROM t_trf
UNION ALL
SELECT dest_batch_id, to_store_id, to_location_id, medicine_id, 1, 'transfer_in', qty, purchase,
       'stock_transfer', transfer_id, NULL, transfer_number, user_id, user_name, at
FROM t_trf;

INSERT INTO stock_movements (location_id, pharmacy_store_id, medicine_id, medicine_batch_id, movement_type, quantity,
                             quantity_before, quantity_after, unit_cost, reference_type, reference_id, reason, notes,
                             performed_by, performed_by_name, movement_date, created_at)
SELECT location_id, store_id, medicine_id, batch_id, movement_type, qty,
       running - qty, running, unit_cost, ref_type, ref_id, reason, notes, user_id, user_name, at, at
FROM (SELECT m.*, sum(qty) OVER (PARTITION BY batch_id ORDER BY seq ROWS UNBOUNDED PRECEDING) AS running FROM t_mv m) r
ORDER BY at;

-- Every 17th received batch sells out: one last dispensing takes it to zero.
INSERT INTO stock_movements (location_id, pharmacy_store_id, medicine_id, medicine_batch_id, movement_type, quantity,
                             quantity_before, quantity_after, unit_cost, notes, performed_by, performed_by_name,
                             movement_date, created_at)
SELECT l.location_id, l.store_id, l.medicine_id, l.batch_id, 'dispensing', -bal.qty, bal.qty, 0, l.purchase,
       'OPD counter', l.user_id, l.user_name, l.created_at + interval '95 days', l.created_at + interval '95 days'
FROM t_line l
JOIN (SELECT medicine_batch_id, sum(quantity)::int AS qty FROM stock_movements GROUP BY medicine_batch_id) bal
  ON bal.medicine_batch_id = l.batch_id
WHERE l.ln % 17 = 0 AND bal.qty > 0
ORDER BY l.created_at;

-- Batch balances are the sum of their ledger.
UPDATE medicine_batches b
SET quantity_available = t.available,
    damaged_quantity = t.damaged,
    status = CASE WHEN b.expiry_date < current_date THEN 'expired'
                  WHEN t.available = 0 THEN 'exhausted'
                  ELSE 'active' END
FROM (SELECT medicine_batch_id,
             sum(quantity)::int AS available,
             coalesce(-sum(quantity) FILTER (WHERE movement_type = 'damage'), 0)::int AS damaged
      FROM stock_movements GROUP BY medicine_batch_id) t
WHERE b.id = t.medicine_batch_id
  AND b.id IN (SELECT batch_id FROM t_line UNION ALL SELECT dest_batch_id FROM t_trf);

UPDATE medicine_batches b
SET status = CASE WHEN b.id % 3 = 0 THEN 'recalled' ELSE 'blocked' END,
    blocked_reason = CASE WHEN b.id % 3 = 0 THEN 'Manufacturer recall notice' ELSE 'Quality complaint — pending lab test' END,
    blocked_by = s.pharmacist_id,
    blocked_at = now()::timestamp - (b.id % 60) * interval '1 day'
FROM t_store s
WHERE s.id = b.pharmacy_store_id AND b.status = 'active' AND b.id % 97 = 0;

-- =============================================================================
--  6. Audit trail and email log
-- =============================================================================

INSERT INTO activity_logs (user_id, actor_name, actor_type, event, entity_type, entity_id, entity_label, before, after,
                           ip_address, created_at)
SELECT user_id, actor_name, 'tenant', event, entity_type, entity_id, entity_label, before, after, ip, at
FROM (
    SELECT u.id AS user_id, u.name AS actor_name, 'created' AS event, 'Customer' AS entity_type, c.id AS entity_id,
           c.name AS entity_label, NULL::jsonb AS before,
           jsonb_build_object('name', c.name, 'phone', c.phone, 'code', c.code) AS after,
           '192.168.1.' || (c.id % 250 + 2) AS ip, c.created_at AS at
    FROM t_cust c
    CROSS JOIN t_mark m
    JOIN t_user u ON u.rn = 1 + (c.rn % 10000)
    WHERE c.id > m.customer_max

    UNION ALL
    SELECT u.id, u.name, 'created', 'Appointment', a.id, a.appointment_date::text, NULL,
           jsonb_build_object('status', 'booked', 'type', a.type, 'appointment_date', a.appointment_date),
           '192.168.2.' || (a.id % 250 + 2), a.created_at
    FROM appointments a
    CROSS JOIN t_mark m
    JOIN t_user u ON u.rn = 1 + (a.id % 10000)
    WHERE a.id > m.appointment_max

    UNION ALL
    SELECT u.id, u.name, 'updated', 'Appointment', a.id, a.appointment_date::text,
           jsonb_build_object('status', 'booked'), jsonb_build_object('status', a.status),
           '192.168.2.' || (a.id % 250 + 2), coalesce(a.completed_at, a.updated_at)
    FROM appointments a
    CROSS JOIN t_mark m
    JOIN t_user u ON u.rn = 1 + ((a.id * 7) % 10000)
    WHERE a.id > m.appointment_max AND a.status IN ('completed', 'cancelled', 'no_show') AND a.id % 3 = 0

    UNION ALL
    SELECT NULL, NULL, 'created', 'Doctor', d.id, d.name, NULL, jsonb_build_object('name', d.name), NULL, now()::timestamp - interval '500 days'
    FROM t_doc d

    UNION ALL
    SELECT NULL, NULL, 'created', 'Medicine', m.id, m.generic_name || coalesce(' (' || m.brand_name || ')', ''), NULL,
           jsonb_build_object('generic_name', m.generic_name, 'strength', m.strength), NULL, m.created_at
    FROM medicines m WHERE m.medicine_code LIKE 'MED-%'
) x
ORDER BY at;

INSERT INTO email_logs (template_key, recipient, subject, status, error_message, sent_at, created_at, updated_at)
SELECT x.key,
       u.email,
       CASE x.key WHEN 'password_reset' THEN 'Reset your password' ELSE 'Set up your account' END,
       CASE WHEN x.failed THEN 'failed' ELSE 'success' END,
       CASE WHEN x.failed THEN pg_temp.pick(ARRAY['Connection could not be established with host smtp.practice.test',
                                                 'Expected response code 250 but got 550: mailbox unavailable',
                                                 'Timed out after 30 seconds']) END,
       CASE WHEN NOT x.failed THEN x.at END,
       x.at, x.at
FROM (SELECT g, CASE WHEN random() < 0.35 THEN 'password_reset' ELSE 'user_account_setup' END AS key,
             random() < 0.05 AS failed,
             now()::timestamp - random() * interval '450 days' AS at
      FROM generate_series(1, 12000) g OFFSET 0) x
JOIN t_user tu ON tu.rn = 1 + (x.g % 10000)
JOIN users u ON u.id = tu.id
ORDER BY x.at;

COMMIT;

ANALYZE;

-- Row counts, biggest first.
SELECT relname AS table_name, n_live_tup AS rows
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY n_live_tup DESC;
