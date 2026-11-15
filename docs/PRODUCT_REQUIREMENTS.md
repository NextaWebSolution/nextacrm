# NextaCRM — Product Requirements (v1.0)

## 1. Vision
An all-in-one business management CRM for IT service businesses (software houses, web/digital agencies, IT consultancies, outsourcing and staff-augmentation firms, startups) that installs in `public_html` on ordinary cPanel hosting and feels like a modern SaaS product.

## 2. Users and roles (default roles shipped)
| Role | Typical person | Default access |
|---|---|---|
| Administrator | Owner / CEO | Everything, all settings |
| Manager | Sales/delivery head | All records of their teams; approve leave, quotes, expenses |
| Sales | BDE / account manager | Own + team leads, deals, customers, quotes |
| Project Member | Developer / designer | Assigned projects, tasks, time entries |
| Support Agent | Support engineer | Tickets, knowledge base, customers (read) |
| Accountant | Finance | Invoices, payments, expenses, reports |
| HR | HR executive | Employees, departments, leave |
Roles are editable; new roles can be created with the permission matrix.

## 3. Functional requirements

### 3.1 CRM & Sales
- **Leads**: source, status, qualification, rating, score (rule-based), estimated value, company size, industry, WhatsApp, follow-up date, assigned user, tags. List + kanban by status. Duplicate warning on email/phone. Convert → customer + contact + deal.
- **Customers** (organizations) and **Contacts** with timeline, related deals/projects/invoices/tickets/files.
- **Deals**: multiple pipelines with stages and probabilities; amount, currency, expected close, recurring (MRR), lost reason, competitor. Kanban drag between stages; weighted forecast.
- **Products & services** catalogue with unit, price, tax.
- **Quotations**: line items, discounts, taxes, currency, terms; PDF; send by email; public accept/decline link; convert to invoice or project.
- **Invoices**: numbering per company, due dates, partial payments, status (draft/sent/partially paid/paid/overdue/cancelled), recurring schedule, PDF, public view link, add billable time.
- **Payments** and **Expenses** (billable to customer/project, receipts as files).
- **Contracts** with start/end dates and status (renewal reminders: v1.1).
- **Web-to-lead forms** with embeddable HTML snippet.

### 3.2 Delivery
- **Projects**: customer, manager, members, status, billing type (fixed/hourly/retainer), budget, dates, progress (from tasks), milestones.
- **Tasks**: project, milestone, parent task, dependencies, assignee, priority, status, estimate, due date, billable, list + kanban + calendar; start/stop timer.
- **Time entries**: per user/task/project, billable flag, rate, invoiced flag.

### 3.3 Support
- **Tickets**: customer/contact, priority, type, status, channel, assignee; threaded public replies and internal notes; SLA first-response and resolution due; breach flag.
- **Knowledge base** articles with categories and publish state.

### 3.4 Team / HR
- **Employees** (linked to users optional), departments, designation, joining date, employment type, salary (restricted by permission).
- **Leave requests** with approval (Approve permission).

### 3.5 Cross-cutting
- Activity timeline (notes, calls, meetings, emails logged, system changes) on every record.
- Files on every record + central document library; storage driver chosen in admin.
- Calendar with events, task due dates, leave.
- Notifications (in-app, e-mail) for assignment, mentions-free comments, due tasks, workflow messages.
- Global search across all modules with keyboard navigation and recent searches.
- Saved views (filters + sort + columns + layout) private or shared.
- Bulk select → assign / change field / delete / export.
- Import CSV with column mapping and dry-run validation.
- Export CSV/XLSX respecting filters and permissions.
- Custom fields on every module (text, textarea, number, decimal, date, datetime, select, multiselect, checkbox, url, email, phone).
- Dashboard with KPIs and charts; Reports (sales, pipeline, invoices aging, time, tickets, leads by source).
- Workflow automation; webhooks; REST API with OpenAPI 3 document.
- Multi-company with company switcher; per-company branding, numbering, currency, taxes.
- Multi-currency with exchange rates.

## 4. Non-functional requirements
| Area | Requirement |
|---|---|
| Hosting | PHP 8.2+, MySQL 8.0+ / MariaDB 10.6+, Apache with mod_rewrite (LiteSpeed OK); no root, Node, Redis, Docker |
| Performance | List page < 150 ms server time for 100k rows with indexes; one CSS + one JS bundle; no runtime CDN |
| Mobile | Usable at 360 px: drawer nav, bottom nav, card lists, touch drag on kanban |
| Accessibility | Keyboard reachable controls, focus rings, WCAG AA contrast in both themes |
| Security | See SECURITY_PLAN.md |
| Upgrades | Signed update packages, pre-update DB/file backup, versioned migrations |
| i18n readiness | All UI strings through `__()`; currency/date formats per company |

## 5. Out of scope for v1 (roadmap)
Client portal, e-mail inbox sync (IMAP), double-entry accounting, payroll, Gantt chart, e-signature, WhatsApp provider adapters (webhook-ready only), AI features.
