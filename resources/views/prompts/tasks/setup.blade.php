Set up the development environment for this repository.

**Repository:** {{ $repoName }}

**Steps:**
1. Read README.md, CLAUDE.md, docker-compose.yml, and any config files to understand the project setup.
2. Start the dev environment: run `docker-compose up -d` if a docker-compose.yml exists.
3. Install dependencies (e.g., `composer install`, `npm install`, `pip install -r requirements.txt` — whatever the project uses).
4. Run database migrations and seed data if applicable.
5. Verify the environment: start the dev server and run the test suite.
6. Report success or failure with details about what was set up and any issues encountered.

**Important:**
- The dev environment must persist after setup — subsequent tasks will use `docker-compose start` / `docker-compose stop`.
- Do NOT make any code changes. This is an environment setup task only.
- If any step fails, report the failure with full error details so it can be diagnosed.