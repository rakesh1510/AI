-- Phase 8D rollback: removes ONLY the three tables created by phase8d_finance.sql. The CRM, submissions and every other table are not touched.
DROP TABLE IF EXISTS fin_adjustment, fin_target, fin_expense_entry;
