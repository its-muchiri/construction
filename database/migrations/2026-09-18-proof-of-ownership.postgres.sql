-- equipment_listings.ownership_kyc_document_id is NOT NULL and references kyc_documents,
-- but kyc_documents.document_type had no value for a proof-of-ownership /
-- hire-purchase document, so no equipment listing could ever be created.
ALTER TABLE kyc_documents DROP CONSTRAINT IF EXISTS kyc_documents_document_type_check;
ALTER TABLE kyc_documents ADD CONSTRAINT kyc_documents_document_type_check
    CHECK (document_type IN ('national_id', 'kra_pin', 'business_registration', 'insurance_certificate', 'professional_certification', 'proof_of_address', 'proof_of_ownership'));
