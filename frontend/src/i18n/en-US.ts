import type { TranslationDictionary } from "@/i18n/types";

export const enUS: TranslationDictionary = {
  "common.language": "Language",
  "common.arabic": "العربية",
  "common.english": "English",
  "common.loading": "Loading...",
  "common.search": "Search",
  "common.showAll": "View all",
  "common.logout": "Sign out",
  "common.systemRunning": "System operational",
  "common.underConstruction": "This module is under construction",

  "navigation.home": "Home",
  "navigation.dashboard": "Dashboard",
  "navigation.salesAndCustomers": "Sales & Customers",
  "navigation.crm": "Leads",
  "navigation.customers": "Customers",
  "navigation.reservations": "Reservations",
  "navigation.contracts": "Contracts",
  "navigation.realEstateDevelopment": "Real Estate Development",
  "navigation.projects": "Projects",
  "navigation.units": "Units",
  "navigation.contractors": "Contractors",
  "navigation.contractorStatements": "Contractor Statements",
  "navigation.finance": "Finance",
  "navigation.collections": "Installments & Collections",
  "navigation.expenses": "Expenses",
  "navigation.financialReports": "Financial Reports",
  "navigation.administration": "Administration",
  "navigation.employeesAndTasks": "Employees & Tasks",
  "navigation.usersAndPermissions": "Users & Permissions",
  "navigation.reports": "Reports",
  "navigation.settings": "Settings",
  "navigation.collapseSidebar": "Collapse sidebar",
  "navigation.expandSidebar": "Expand sidebar",
  "navigation.openSidebar": "Open sidebar",
  "navigation.closeSidebar": "Close sidebar",

  "roles.systemOwner": "System Owner",
  "roles.administrator": "Company Administrator",
  "roles.projectManager": "Project Manager",
  "roles.sales": "Sales",
  "roles.accountant": "Accountant",
  "roles.employee": "Employee",

  "auth.welcomeBack": "Welcome back",
  "auth.login": "Sign in",
  "auth.loginDescription":
    "Enter your account details to access the dashboard.",
  "auth.email": "Email address",
  "auth.emailPlaceholder": "name@company.com",
  "auth.password": "Password",
  "auth.passwordPlaceholder": "••••••••••••",
  "auth.submit": "Sign in",
  "auth.submitting": "Signing in...",
  "auth.loginFailed": "Unable to sign in.",
  "auth.showOrHidePassword": "Show or hide password",
  "auth.platformLabel": "Real Estate Development Platform",
  "auth.heroTitleLine1": "Manage your business",
  "auth.heroTitleLine2": "from one place",
  "auth.heroDescription":
    "Projects, customers, units, contracts and collections within one clear workspace.",

  "dashboard.title": "Dashboard",
  "dashboard.description":
    "A complete view of company performance and key operations",
  "dashboard.greetingMorning": "Good morning",
  "dashboard.quickActions.newProject": "New project",
  "dashboard.quickActions.newCustomer": "New customer",
  "dashboard.quickActions.newContract": "New contract",
  "dashboard.quickActions.newPayment": "New payment",
  "dashboard.stats.projects": "Projects",
  "dashboard.stats.customers": "Customers",
  "dashboard.stats.units": "Units",
  "dashboard.stats.contracts": "Contracts",
  "dashboard.stats.noProjects": "No projects registered",
  "dashboard.stats.noCustomers": "No customers yet",
  "dashboard.stats.noUnits": "No real estate units",
  "dashboard.stats.noContracts": "No contracts registered",
  "dashboard.currentProjects": "Current projects",
  "dashboard.currentProjectsDescription":
    "Track active project progress",
  "dashboard.noProjects": "No projects yet",
  "dashboard.noProjectsDescription":
    "Projects and progress will appear after data is added.",
  "dashboard.recentActivity": "Recent activity",
  "dashboard.recentActivityDescription":
    "Latest operations within the system",
  "dashboard.noActivity": "No activity",
  "dashboard.noActivityDescription":
    "User operations will appear here.",
  "dashboard.collectionsSummary": "Collections summary",
  "dashboard.collectionsDescription":
    "Installment and payment status",
  "dashboard.noCollections": "No collections",
  "dashboard.noCollectionsDescription":
    "Collection data will appear here when available.",
  "dashboard.dueThisMonth": "Due this month",
  "dashboard.collected": "Collected",
  "dashboard.overdue": "Overdue",
  "dashboard.alerts": "Alerts",
  "dashboard.alertsDescription":
    "Items that require attention",
  "dashboard.noAlerts": "No alerts",
  "dashboard.noAlertsDescription":
    "Alerts that need attention will appear here.",
  "dashboard.noOverdueInstallments":
    "No overdue installments",
  "dashboard.noOverdueInstallmentsDescription":
    "All current installments are in good standing",
  "dashboard.noDelayedProjects": "No delayed projects",
  "dashboard.noDelayedProjectsDescription":
    "All projects are currently on schedule",
  "dashboard.noUrgentAlerts": "No urgent alerts",
  "dashboard.noUrgentAlertsDescription":
    "Nothing requires your attention right now",

  "collection.absent.title": "No collection schedule",
  "collection.absent.description":
    "No collection schedule has been created for this contract yet.",
  "collection.absent.create": "Create collection schedule",
  "collection.draft.title": "Draft collection schedule",
  "collection.draft.badge": "Draft",
  "collection.draft.edit": "Edit draft",
  "collection.draft.save": "Save draft",
  "collection.draft.saving": "Saving...",
  "collection.draft.editorTitle": "Edit draft collection schedule",
  "collection.draft.editorDescription":
    "Enter the schedule items and save the draft. A draft total may be partial.",
  "collection.finalize.button": "Finalize collection schedule",
  "collection.finalize.dialog.title":
    "Finalize collection schedule",
  "collection.finalize.dialog.description":
    "The collection schedule will move from Draft to Scheduled. It cannot return to Draft; later financial changes must use an amendment.",
  "collection.finalize.dialog.confirm": "Finalize schedule",
  "collection.finalize.dialog.confirming": "Finalizing...",
  "collection.finalize.dialog.cancel": "Cancel",
  "collection.finalize.dialog.close": "Close",
  "collection.finalize.dialog.lineCount":
    "Collection item count",
  "collection.finalize.totalsMismatch":
    "The collection schedule total does not equal the contract value.",
  "collection.scheduled.title": "Approved collection schedule",
  "collection.scheduled.badge": "Approved",
  "collection.cancelled.title": "Cancelled collection schedule",
  "collection.cancelled.badge": "Cancelled",
  "collection.cancelled.notice":
    "The collection schedule for this contract is cancelled. Its history can be viewed below.",
  "collection.line.sequence": "#",
  "collection.line.title": "Collection item",
  "collection.line.amount": "Amount",
  "collection.line.dueDate": "Due date",
  "collection.line.notes": "Notes",
  "collection.line.delete": "Delete line",
  "collection.line.addLine": "Add line",
  "collection.line.moveUp": "Move line up",
  "collection.line.moveDown": "Move line down",
  "collection.summary.activeTotal": "Collection schedule total",
  "collection.summary.contractTotal": "Contract value",
  "collection.summary.lineCount": "Item count",
  "collection.summary.scheduledAt": "Approved at",
  "collection.summary.scheduledBy": "Approved by",
  "collection.loading": "Loading collection schedule...",
  "collection.loadError": "Unable to load the collection schedule.",
  "collection.retry": "Try again",
  "collection.genericError": "Unable to complete the operation.",
  "collection.discard.title": "Discard changes?",
  "collection.discard.description":
    "All unsaved collection schedule changes will be lost.",
  "collection.discard.confirm": "Discard changes",
  "collection.discard.cancel": "Continue editing",
  "collection.discard.close": "Close",
  "collection.validation.titleRequired":
    "The collection item is required.",
  "collection.validation.titleTooLong":
    "The collection item must not exceed 150 characters.",
  "collection.validation.amountInvalid":
    "Enter a positive amount with no more than two decimal places.",
  "collection.validation.dateInvalid":
    "Enter a valid due date.",
  "collection.validation.sequenceDuplicate":
    "Sequence numbers must be unique.",
  "collection.validation.dueDateOrder":
    "Due dates must be non-decreasing by sequence.",
  "collection.error.draftNotEligible":
    "The draft collection schedule cannot be edited in the contract's current state.",
  "collection.error.notDraft":
    "The collection schedule is not in Draft state.",
  "collection.error.finalizeNotEligible":
    "The collection schedule cannot be finalized in the contract's current state.",
  "collection.error.amendNotEligible":
    "The collection schedule cannot be amended in the contract's current state.",
  "collection.error.noActiveSchedule":
    "There is no active collection schedule to amend.",
  "collection.error.scheduleChanged":
    "The collection schedule changed since it was loaded. Refreshing the data...",
  "collection.error.integrity":
    "The collection schedule cannot be displayed because its data is inconsistent. Contact support.",
  "collection.error.invalidGeneration":
    "The amendment could not be completed because of a technical error. Reload the page.",
  "collection.error.totalMismatch":
    "The collection schedule total does not equal the contract value.",
  "collection.error.duplicateSequence":
    "Sequence numbers are duplicated. Review the lines and try again.",
  "collection.error.dueDateOrder":
    "Due dates must be non-decreasing by sequence.",
  "collection.error.blankTitle":
    "A collection item title is blank.",
  "collection.error.invalidAmount":
    "A collection item amount is invalid.",
  "collection.error.decimalPrecision":
    "Amounts must not exceed two decimal places.",
  "collection.error.invalidReason":
    "The cancellation reason is invalid or too long.",
  "collection.error.notAuthorized":
    "You are not authorized to perform this action.",
  "collection.error.serviceUnavailable":
    "The service is temporarily unavailable. Try again.",
  "collection.error.invalidQuery":
    "The request is invalid. Reload the page.",

  "pages.crm.title": "Leads",
  "pages.crm.description":
    "Track leads, sources and communication history",
  "pages.customers.title": "Customers",
  "pages.customers.description":
    "Manage customer records, files and notes",
  "pages.reservations.title": "Reservations",
  "pages.reservations.description":
    "Manage reservations, deposits and expiry dates",
  "pages.contracts.title": "Contracts",
  "pages.contracts.description":
    "Manage sales contracts, payments and related files",
  "pages.projects.title": "Projects",
  "pages.projects.description":
    "Manage projects, budgets and completion rates",
  "pages.units.title": "Units",
  "pages.units.description":
    "Manage real estate units, prices and statuses",
  "pages.contractors.title": "Contractors",
  "pages.contractors.description":
    "Manage contractor records, contracts and projects",
  "pages.contractorStatements.title": "Contractor Statements",
  "pages.contractorStatements.description":
    "Track contractor statements and completion rates",
  "pages.collections.title": "Installments & Collections",
  "pages.collections.description":
    "Track paid, remaining and overdue installments",
  "pages.expenses.title": "Expenses",
  "pages.expenses.description":
    "Record project, supplier and attachment expenses",
  "pages.financialReports.title": "Financial Reports",
  "pages.financialReports.description":
    "Review revenue, collections and expenses",
  "pages.employees.title": "Employees & Tasks",
  "pages.employees.description":
    "Manage employees, tasks and deadlines",
  "pages.users.title": "Users & Permissions",
  "pages.users.description":
    "Manage accounts, roles and permissions",
  "pages.reports.title": "Reports",
  "pages.reports.description":
    "Operational reports for monitoring company performance",
  "pages.settings.title": "Settings",
  "pages.settings.description":
    "Company identity and system settings",
};
