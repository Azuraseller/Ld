-- Apply once in production/staging after reviewing the existing indexes.
CREATE INDEX `legacy_records_scope_record_id` ON `legacy_records` (`scopeKey`,`recordType`,`id`);
