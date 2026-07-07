# Meros CRM

A Salesforce-focused CRM package for the Meros framework.

## Scope

This package owns Salesforce-specific integrations, external models, and form actions.

## Action Types

The package provides two patterns for CRM writes:

- `create_salesforce_contact`: typed, explicit action that uses a dedicated external model (`SalesforceContacts`) and a fixed contact payload contract.
- `run_crm_sync_jobs`: configurable action for one or more generic write jobs using integration handle + object/endpoint + mapping rows.

Use `create_salesforce_contact` when you want predictable contact behavior and cleaner code-level guarantees.

Use `run_crm_sync_jobs` when you need admin-configurable object targets or multiple writes without creating a new action class.

## License

MIT
