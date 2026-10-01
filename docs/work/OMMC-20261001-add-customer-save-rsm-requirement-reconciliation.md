# OMMC-20261001-add-customer-save-rsm-requirement-reconciliation

- **Title:** Tablet Add Customer save — RSM requirement reconciliation
- **Objective:** Allow Tablet/iPad Add Customer to save with selected Access users without requiring an RSM relationship, as clarified by OMMC.
- **Repositories:** Portal ommc2027 (authority inspection and equivalent Work record); Tablet ommc2027-tablet (bounded implementation).
- **State:** TERMINAL_DELIVERED
- **Implementation authorization:** Granted by explicit Human GO.
- **Terminal authorization:** Granted by explicit Human TERMINAL: GO; renewed and confirmed after functional acceptance.
- **Authoritative Human business decision:** OMMC clarification dated 9/28: “issue no RSM selection needed in customer - ipad (remove validation)”.
- **Human-authorized scope:** Remove only the Tablet Add Customer RSM relationship validation that blocks saving; preserve Access validation, resolution, and persistence.
- **Constraints:** No RSM field, auto-assignment, manufactured relationship, schema/migration/database change, reference-data projection, sync redesign, Portal application change, or unrelated Customer behavior change.
- **Confirmed failure path:** Tablet CustomerCreatePage::saveCustomer() called validateScopedPortalRules(), which queried selected users for null rsm_id and added “Each assigned Access user must have an RSM relationship.” before Customer persistence.
- **Portal authority:** Portal derives displayed RSM from selected Access users’ users.rsm_id and applies the same Access-user RSM rule, but the OMMC decision specifically removes the blocking requirement from Tablet/iPad Add Customer.
- **Root cause classification:** Tablet Add Customer incorrectly treated the Portal Access/RSM relationship rule as a mandatory local save prerequisite, contrary to the accepted iPad requirement.
- **Implementation files:** Tablet app/Filament/Pages/CustomerCreatePage.php and focused Customer Add test coverage. No Portal application files.
- **Validation:** Tablet focused RSM/Add Customer coverage passed: 2 tests, 10 assertions. Customer Code regression coverage passed: 4 tests, 16 assertions. Conversion Program regression coverage passed: 1 test, 3 assertions. The full existing Customer diagnostic file passed 3 tests and retained 1 unrelated pre-existing render assertion failure for 2018 *. PHP syntax checks passed; Blade view cache completed; Pint/checks and git diff --check passed.
- **Human acceptance:** Tablet browser PASS. Physical iPad PASS. Human confirmed the Add Customer RSM validation issue is no longer reproducible and Customer creation succeeds without RSM selection or assignment.
- **Blockers:** None currently.
- **Remaining work:** None within this bounded Work. The separate Customer-list visibility issue is excluded and belongs to a subsequent Work.
- **Delivery evidence:** Tablet terminal reconciliation commit and push are recorded in the terminal delivery report. No unrelated Customer-list visibility work was included.
