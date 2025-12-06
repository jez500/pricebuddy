## Approach to tasks

* Favour TDD when completing tasks, usually a test class exists for the task already, assume you have
  to make one if it doesn't exist.
* After each major task, provide evidence of the test passing.
* If the playwright mcp is available, use it! the base url is `http://price-buddy.lndo.site/admin`, login
  email: `test@test.com`, password: `password`. If user doesn't exist, seed UserSeder. Important for:
  * Debugging
  * Task completion evidence
  * Base line testing
* If the laravel mcp is available, use it!

## Post task completion

* Run `lando phpcs-fix && lando phpcs` to fix any coding standards issues and run a phpstan analysis.

## Documentation

* Documentation files for this project are found in `ls docs/docs/*.md` (from project root)
* You should consult these docs if you require more context about the project and its functionality
* You should check if the documentation needs creating or updating after completing major tasks
