# Code walkthrough progress

Order follows dependencies: each component only calls things already explained above it.

- [x] **1. Bootstrap, autoloader, hook wiring, install/upgrade, front-end title and meta description output**: `walkthrough/01-bootstrap-and-frontend-output.md` (understood)
- [x] 2. Settings and API key handling: `walkthrough/02-settings-and-api-key.md` (explained; awaiting your confirmation)
- [ ] 3. SEO plugin compatibility and field ownership (`Compat`)
- [ ] 4. Text and parsing (`Text`, `Parser`: headings, images, links, paragraph segments)
- [ ] 5. Audit engine (`Audit`: findings, criteria, H1/headings, images, meta checks)
- [ ] 6. Audit runner and page settings (`Audit_Runner`, `Intent`)
- [ ] 7. Storage layer (`Recs`, `History`)
- [ ] 8. AI pipeline (`Prompt`, `AI_Client`, `Rate_Limiter`, `Validator`)
- [ ] 9. Generate and review workflow (`Workspace::handle_generate`, `handle_rec_action`, `handle_save_alt`)
- [ ] 10. Approval: plan, apply, draft copy, rollback (`Approval`, `Diff`)
- [ ] 11. Site-wide analysis: similarity, content gap, internal links (`Similarity`, `Gap`, `Links`, `Workspace::analyse_site`)
- [ ] 12. Live output check (`Live_Check`)
- [ ] 13. Search data import and reports (`Searchdata`, `Admin_Data`, `Report`)
- [ ] 14. Admin screens (`Admin`, `Workspace_View`)
