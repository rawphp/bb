.PHONY: start

# Herd serves PHP at bodybuilder.test, so there is no artisan serve.
# This runs Vite on :5283 (Bodybuilder's port in ~/EA/projects/AGENTS.md),
# the queue worker (sign-in codes, reset links, reminders) and the scheduler.
# Ctrl-C stops all three. If something already holds :5283 it stops before
# starting anything, so the workers never run without Vite.
start:
	@pid=$$(lsof -tiTCP:5283 -sTCP:LISTEN | head -1); \
	if [ -n "$$pid" ]; then \
		echo "Port 5283 is in use by PID $$pid: $$(ps -o command= -p $$pid)"; \
		echo "Stop it, then run make start again."; \
		exit 1; \
	fi; \
	trap 'kill 0' INT TERM; \
	npm run dev & \
	php artisan queue:listen --tries=1 --timeout=0 & \
	php artisan schedule:work & \
	wait
