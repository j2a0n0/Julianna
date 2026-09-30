# Local code-only hotfix over the last verified Julianna image.
# This is not the source-tagged production release build.
FROM julianna/app:agent-brave-key-settings-20260927
COPY --chown=www-data:www-data app/Domain/AgentUi/Controllers/AiSettingsController.php /var/www/html/app/Domain/AgentUi/Controllers/AiSettingsController.php
