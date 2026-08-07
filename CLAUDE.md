# Claude Instructions

## Project Context

- This is a Laravel application.
- Follow the existing project architecture, naming conventions, and coding style.
- Reuse existing services, helpers, traits, components, and utilities before creating new ones.
- Prefer Laravel's built-in features over custom implementations.
- Write clean, maintainable, and production-ready code.

---

# Development Workflow

Before making changes:

1. Read all relevant files to understand the existing implementation.
2. Understand the complete request before writing code.
3. Identify any existing patterns that should be reused.
4. Explain the implementation plan before making multi-file changes.

For simple bug fixes or isolated features, proceed without asking for confirmation.

---

# Ask for Approval Before

Always ask before performing any of the following:

- Database migrations
- Schema modifications
- Architectural changes
- Large refactors
- Dependency installation or removal
- Package updates
- Configuration changes
- Environment (.env) changes
- Authentication or authorization redesign
- Breaking API changes
- Background job or queue changes

---

# Coding Standards

Always:

- Follow PSR-12.
- Keep controllers thin.
- Put business logic into Services or existing domain classes.
- Use Form Request validation.
- Use Route Model Binding where appropriate.
- Prefer Eloquent relationships.
- Avoid duplicated logic.
- Use dependency injection.
- Use Carbon for date/time handling.
- Keep methods small and focused.
- Return consistent responses.

Never:

- Duplicate existing logic.
- Write business logic inside Blade templates.
- Write business logic directly inside controllers.
- Modify unrelated code.
- Introduce unnecessary abstractions.
- Create new patterns when an existing one already exists.

---

# Database

When working with the database:

- Reuse existing relationships.
- Avoid N+1 queries.
- Use eager loading when appropriate.
- Prefer Eloquent over raw SQL.
- Use transactions for multi-step updates.
- Preserve data integrity.

Never create migrations unless explicitly requested.

---

# Frontend

When modifying Blade views:

- Match the existing UI.
- Reuse Blade components.
- Reuse existing Bootstrap/Tailwind patterns.
- Keep layouts responsive.
- Minimize duplicated HTML.

When modifying JavaScript:

- Follow the existing structure.
- Avoid unnecessary libraries.
- Reuse existing utilities.

---

# Security

Always:

- Validate all user input.
- Authorize protected actions.
- Escape output when appropriate.
- Prevent mass assignment vulnerabilities.
- Protect sensitive operations.

Never:

- Expose secrets
- Expose API keys
- Expose credentials
- Log sensitive information

---

# Performance

Always consider:

- Database query optimization
- Eager loading
- Pagination
- Caching existing expensive queries
- Avoid unnecessary loops
- Avoid duplicate queries

---

# Business Logic

Do not assume business rules.

If requirements are ambiguous:

- Ask concise clarification questions.
- Do not invent workflows.
- Do not guess validation rules.

---

# File Changes

Keep changes as small as possible.

Only modify files directly related to the task.

Avoid unrelated formatting or refactoring.

---

# Responses

## Before Coding

Provide a short summary including:

- Your understanding of the request
- Files likely to be modified
- Implementation approach

## After Coding

Provide:

### Summary
A concise explanation of what changed.

### Modified Files
List every modified file.

### Notes
Mention any assumptions or important implementation details.

### Follow-up
List recommended improvements, testing, or next steps if applicable.

---

# Code Quality

Generated code should be:

- Production-ready
- Readable
- Maintainable
- Well-structured
- Consistent with the existing codebase
- Free of unnecessary comments
- Easy to extend

Always optimize for long-term maintainability rather than the shortest implementation.