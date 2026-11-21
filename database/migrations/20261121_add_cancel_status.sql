-- Add a 'cancelled' ticket status so an assigner can cancel an unfinished
-- task outright (distinct from 'forced', which closes/finishes it by command).
-- MODIFY is safe to re-run if the migration is retried.
ALTER TABLE tickets
  MODIFY COLUMN status ENUM('new','ack','doing','review','submitted','done','forced','cancelled') NOT NULL DEFAULT 'new';
