.PHONY: start

# Herd serves PHP at bodybuilder.test, so there is no artisan serve.
# This runs Vite on :5283 (Bodybuilder's port in ~/EA/projects/AGENTS.md),
# the queue worker (sign-in codes, reset links, reminders) and the scheduler.
# Ctrl-C stops all three.
start:
	@trap 'kill 0' INT TERM; \
	npm run dev & \
	php artisan queue:listen --tries=1 --timeout=0 & \
	php artisan schedule:work & \
	wait
