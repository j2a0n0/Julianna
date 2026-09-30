# Local code-only hotfix over the last verified Julianna image.
# This is not the source-tagged production release build.
FROM julianna/app:agent-model-selector-hotfix-20260927
COPY --chown=www-data:www-data app/Domain/Agent/AI/AgentPrompt.php /var/www/html/app/Domain/Agent/AI/AgentPrompt.php
COPY --chown=www-data:www-data app/Domain/Agent/AI/ReplyLocale.php /var/www/html/app/Domain/Agent/AI/ReplyLocale.php
COPY --chown=www-data:www-data app/Domain/Agent/Services/Agent.php /var/www/html/app/Domain/Agent/Services/Agent.php
