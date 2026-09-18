-- MySQL/MariaDB counterpart of 2026-09-18-proof-of-ownership.postgres.sql.
ALTER TABLE kyc_documents MODIFY document_type
    ENUM('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address', 'proof_of_ownership') NOT NULL;
