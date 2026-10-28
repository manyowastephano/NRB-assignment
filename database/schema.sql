-- =====================================================================
-- National ID Registration System (NRB, Malawi)
-- File 1 of 3: database and table creation (physical model)
-- Engine: MySQL 8.0.16+ / MariaDB 10.2+ (CHECK constraints are enforced)
-- =====================================================================

DROP DATABASE IF EXISTS nrb_db;
CREATE DATABASE nrb_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nrb_db;

-- ---------- Location tables ----------
CREATE TABLE district (
  district_id   INT AUTO_INCREMENT PRIMARY KEY,
  district_name VARCHAR(50) NOT NULL UNIQUE,
  region        VARCHAR(10) NOT NULL,
  CONSTRAINT chk_district_region CHECK (region IN ('Northern','Central','Southern'))
) ENGINE=InnoDB;

CREATE TABLE traditional_authority (
  ta_id       INT AUTO_INCREMENT PRIMARY KEY,
  ta_name     VARCHAR(60) NOT NULL,
  chief_name  VARCHAR(80),
  chief_title VARCHAR(30),
  district_id INT NOT NULL,
  CONSTRAINT fk_ta_district FOREIGN KEY (district_id)
    REFERENCES district(district_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE address (
  address_id           INT AUTO_INCREMENT PRIMARY KEY,
  village              VARCHAR(60) NOT NULL,
  physical_description VARCHAR(255),
  postal_box           VARCHAR(30),
  ta_id                INT NOT NULL,
  CONSTRAINT fk_address_ta FOREIGN KEY (ta_id)
    REFERENCES traditional_authority(ta_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------- Person and subclasses ----------
CREATE TABLE person (
  person_id      INT AUTO_INCREMENT PRIMARY KEY,
  nid_number     VARCHAR(12) UNIQUE,                 -- assigned at approval, NULL until then
  first_name     VARCHAR(50) NOT NULL,
  middle_name    VARCHAR(50),
  surname        VARCHAR(50) NOT NULL,
  maiden_name    VARCHAR(50),
  date_of_birth  DATE NOT NULL,
  gender         CHAR(1) NOT NULL,
  place_of_birth VARCHAR(80),
  marital_status VARCHAR(15),
  life_status    VARCHAR(10) NOT NULL DEFAULT 'Alive',
  date_of_death  DATE,
  CONSTRAINT chk_person_gender  CHECK (gender IN ('M','F')),
  CONSTRAINT chk_person_marital CHECK (marital_status IN ('Single','Married','Divorced','Widowed')),
  CONSTRAINT chk_person_life    CHECK (life_status IN ('Alive','Deceased')),
  CONSTRAINT chk_person_death   CHECK (date_of_death IS NULL OR date_of_death >= date_of_birth)
) ENGINE=InnoDB;

CREATE TABLE person_phone (
  person_id INT NOT NULL,
  phone_no  VARCHAR(15) NOT NULL,
  PRIMARY KEY (person_id, phone_no),
  CONSTRAINT fk_phone_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE citizen (
  person_id           INT PRIMARY KEY,
  citizenship_basis   VARCHAR(30) NOT NULL,
  citizenship_cert_no VARCHAR(30) UNIQUE,
  acquired_on         DATE,
  CONSTRAINT fk_citizen_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE foreign_resident (
  person_id         INT PRIMARY KEY,
  passport_no       VARCHAR(20) NOT NULL,
  country_of_origin VARCHAR(60) NOT NULL,
  permit_no         VARCHAR(30) NOT NULL UNIQUE,
  permit_expiry     DATE NOT NULL,
  CONSTRAINT fk_foreign_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE biometric (
  person_id      INT NOT NULL,
  biometric_code VARCHAR(30) NOT NULL,
  biometric_type VARCHAR(15) NOT NULL,
  template_data  BLOB NOT NULL,
  quality_score  DECIMAL(5,2),
  captured_on    DATETIME NOT NULL,
  PRIMARY KEY (person_id, biometric_code),
  CONSTRAINT fk_bio_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_bio_type  CHECK (biometric_type IN ('Fingerprint','Face photo','Signature')),
  CONSTRAINT chk_bio_score CHECK (quality_score BETWEEN 0 AND 100)
) ENGINE=InnoDB;

CREATE TABLE resides_at (
  person_id    INT NOT NULL,
  address_id   INT NOT NULL,
  from_date    DATE NOT NULL,
  to_date      DATE,                                  -- NULL = current address
  address_type VARCHAR(15),
  PRIMARY KEY (person_id, address_id, from_date),
  CONSTRAINT fk_res_person  FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_res_address FOREIGN KEY (address_id)
    REFERENCES address(address_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_res_dates CHECK (to_date IS NULL OR to_date >= from_date),
  CONSTRAINT chk_res_type  CHECK (address_type IN ('Permanent','Temporary','Postal'))
) ENGINE=InnoDB;

CREATE TABLE parent_of (
  parent_id   INT NOT NULL,
  child_id    INT NOT NULL,
  parent_type VARCHAR(10),
  PRIMARY KEY (parent_id, child_id),
  CONSTRAINT fk_parent FOREIGN KEY (parent_id)
    REFERENCES person(person_id) ON DELETE CASCADE,
  CONSTRAINT fk_child  FOREIGN KEY (child_id)
    REFERENCES person(person_id) ON DELETE CASCADE,
  CONSTRAINT chk_parent_type CHECK (parent_type IN ('Mother','Father','Guardian')),
  CONSTRAINT chk_parent_self CHECK (parent_id <> child_id)
) ENGINE=InnoDB;

-- ---------- Registration centres, officers, applications ----------
CREATE TABLE registration_centre (
  centre_id   INT AUTO_INCREMENT PRIMARY KEY,
  centre_name VARCHAR(80) NOT NULL,
  centre_type VARCHAR(20),
  status      VARCHAR(10) NOT NULL DEFAULT 'Active',
  district_id INT NOT NULL,
  CONSTRAINT fk_centre_district FOREIGN KEY (district_id)
    REFERENCES district(district_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_centre_status CHECK (status IN ('Active','Inactive'))
) ENGINE=InnoDB;

CREATE TABLE officer (
  officer_id  INT AUTO_INCREMENT PRIMARY KEY,
  employee_no VARCHAR(20) NOT NULL UNIQUE,
  first_name  VARCHAR(50) NOT NULL,
  surname     VARCHAR(50) NOT NULL,
  job_role    VARCHAR(40),
  centre_id   INT NOT NULL,
  CONSTRAINT fk_officer_centre FOREIGN KEY (centre_id)
    REFERENCES registration_centre(centre_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE application (
  application_id    INT AUTO_INCREMENT PRIMARY KEY,
  form_no           VARCHAR(20) NOT NULL,
  receipt_no        VARCHAR(30) NOT NULL UNIQUE,
  application_type  VARCHAR(15) NOT NULL,
  application_date  DATE NOT NULL,
  late_registration TINYINT(1) NOT NULL DEFAULT 0,
  status            VARCHAR(10) NOT NULL DEFAULT 'Pending',
  rejection_reason  VARCHAR(255),
  person_id         INT NOT NULL,
  centre_id         INT NOT NULL,
  captured_by       INT NOT NULL,
  approved_by       INT,
  approval_date     DATE,
  CONSTRAINT fk_app_person   FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_app_centre   FOREIGN KEY (centre_id)
    REFERENCES registration_centre(centre_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_app_captured FOREIGN KEY (captured_by)
    REFERENCES officer(officer_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_app_approved FOREIGN KEY (approved_by)
    REFERENCES officer(officer_id) ON DELETE RESTRICT,
  CONSTRAINT chk_app_type   CHECK (application_type IN ('New','Replacement','Renewal')),
  CONSTRAINT chk_app_status CHECK (status IN ('Pending','Approved','Rejected')),
  CONSTRAINT chk_app_approval_date CHECK (approval_date IS NULL OR approval_date >= application_date),
  CONSTRAINT chk_app_approved CHECK (status <> 'Approved' OR (approved_by IS NOT NULL AND approval_date IS NOT NULL)),
  CONSTRAINT chk_app_rejected CHECK (status <> 'Rejected' OR rejection_reason IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE supporting_document (
  application_id    INT NOT NULL,
  doc_seg           INT NOT NULL,
  doc_type          VARCHAR(40) NOT NULL,
  doc_number        VARCHAR(40),
  issuing_authority VARCHAR(80),
  verified          TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (application_id, doc_seg),
  CONSTRAINT fk_doc_app FOREIGN KEY (application_id)
    REFERENCES application(application_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------- Witness (category of PERSON / TRADITIONAL_AUTHORITY) ----------
CREATE TABLE witness (
  witness_id   INT AUTO_INCREMENT PRIMARY KEY,
  witness_type VARCHAR(10) NOT NULL,
  person_id    INT UNIQUE,
  ta_id        INT UNIQUE,
  CONSTRAINT fk_witness_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE RESTRICT,
  CONSTRAINT fk_witness_ta FOREIGN KEY (ta_id)
    REFERENCES traditional_authority(ta_id) ON DELETE RESTRICT,
  CONSTRAINT chk_witness_type CHECK (witness_type IN ('Person','TA')),
  CONSTRAINT chk_witness_one CHECK (
       (witness_type = 'Person' AND person_id IS NOT NULL AND ta_id IS NULL)
    OR (witness_type = 'TA'     AND ta_id IS NOT NULL     AND person_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE witness_application (
  witness_id     INT NOT NULL,
  application_id INT NOT NULL,
  witness_date   DATE NOT NULL,
  PRIMARY KEY (witness_id, application_id),
  CONSTRAINT fk_wa_witness FOREIGN KEY (witness_id)
    REFERENCES witness(witness_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_wa_app FOREIGN KEY (application_id)
    REFERENCES application(application_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------- ID card ----------
CREATE TABLE id_card (
  card_id         INT AUTO_INCREMENT PRIMARY KEY,
  card_serial_no  VARCHAR(20) NOT NULL UNIQUE,
  card_type       VARCHAR(20),
  issue_date      DATE NOT NULL,
  expiry_date     DATE NOT NULL,
  card_status     VARCHAR(10) NOT NULL DEFAULT 'Active',
  collection_date DATE,
  application_id  INT NOT NULL UNIQUE,                -- keeps RESULTS_IN one-to-one
  person_id       INT NOT NULL,
  CONSTRAINT fk_card_app    FOREIGN KEY (application_id)
    REFERENCES application(application_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_card_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_card_expiry CHECK (expiry_date > issue_date),
  CONSTRAINT chk_card_status CHECK (card_status IN ('Active','Lost','Expired','Replaced')),
  CONSTRAINT chk_card_collect CHECK (collection_date IS NULL OR collection_date >= issue_date)
) ENGINE=InnoDB;

-- ---------- Institutions and verification log ----------
CREATE TABLE institution (
  institution_id   INT AUTO_INCREMENT PRIMARY KEY,
  institution_name VARCHAR(100) NOT NULL,
  sector           VARCHAR(40),
  agreement_ref    VARCHAR(40),
  agreement_status VARCHAR(10),
  CONSTRAINT chk_inst_status CHECK (agreement_status IN ('Active','Suspended','Expired'))
) ENGINE=InnoDB;

CREATE TABLE verification_log (
  log_id           INT AUTO_INCREMENT PRIMARY KEY,
  request_datetime DATETIME NOT NULL,
  method           VARCHAR(30),
  outcome          VARCHAR(10),
  institution_id   INT NOT NULL,
  person_id        INT NOT NULL,
  CONSTRAINT fk_log_inst   FOREIGN KEY (institution_id)
    REFERENCES institution(institution_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_log_person FOREIGN KEY (person_id)
    REFERENCES person(person_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_log_outcome CHECK (outcome IN ('Match','No match','Error'))
) ENGINE=InnoDB;

-- Note: columns that appear in CHECK constraints (parent_id, child_id, approved_by,
-- person_id/ta_id in witness) use no ON UPDATE CASCADE, because MySQL/MariaDB do not
-- allow that combination.

-- Helpful search indexes
CREATE INDEX idx_person_name ON person (surname, first_name);
CREATE INDEX idx_app_person  ON application (person_id);
CREATE INDEX idx_app_status  ON application (status);
