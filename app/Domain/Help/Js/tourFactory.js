leantime.tourFactory = (function () {

    /**
     * Create a new tour with default settings
     * @param {string} tourName - The name of the tour
     * @returns {Object} - Shepherd tour object
     */
    var createTour = function(tourName) {
        return new Shepherd.Tour({
            useModalOverlay: true,
            defaultStepOptions: {
                classes: 'shepherd-theme-arrows',
                scrollTo: false,
                cancelIcon: {
                    enabled: true,
                    label: leantime.i18n.__("tour.close")
                }
            },
            tourName: tourName
        });
    };

    /**
     * Register a tour completion
     * @param {string} tourName - The name of the tour that was completed
     */
    var registerTourCompletion = function(tourName) {
        leantime.helperRepository.updateUserModalSettings(tourName);

        // Track tour completion for analytics
        if (typeof _paq !== 'undefined') {
            _paq.push(['trackEvent', 'Tour', 'Completed', tourName]);
        }
    };

    /**
     * Get tour definitions for a specific tour
     * @param {string} tourName - The name of the tour
     * @returns {Array} - Array of tour step definitions
     */
    var getTourDefinition = function(tourName) {
        const translate = function(key) {
            return leantime.i18n.__("tour.guide." + key);
        };
        const linkedText = function(key) {
            return translate(key).replace('%s', leantime.appUrl);
        };
        const tourDefinitions = {
            'myWorkDashboard': [
                {
                    id: "welcome-step",
                    title: translate('my_work.welcome.title'),
                    text: translate('my_work.welcome.text'),
                },
                {
                    id: "top-nav-step",
                    title: translate('my_work.modes.title'),
                    text: translate('my_work.modes.text'),
                    attachTo: { element: '.work-modes', on: 'bottom' }
                },
                {
                    id: "menu-step",
                    title: translate('my_work.navigation.title'),
                    text: translate('my_work.navigation.text'),
                    attachTo: { element: '.leftpanel', on: 'right' }
                },
                {
                    id: "my-menu",
                    title: translate('my_work.profile.title'),
                    text: translate('my_work.profile.text'),
                    attachTo: { element: '.headmenu.pull-right', on: 'bottom' }
                },
                {
                    id: "dashboard-widgets",
                    title: translate('my_work.widgets.title'),
                    text: translate('my_work.widgets.text'),
                    attachTo: { element: '.primaryContent', on: 'top' }
                },
                {
                    id: "dashboard-widgets-dandd",
                    title: translate('my_work.customize.title'),
                    text: translate('my_work.customize.text'),
                    attachTo: { element: '#widget_wrapper_todos .grid-handler-top', on: 'bottom' }
                },
                {
                    id: "my-todo-widget",
                    title: translate('my_work.tasks.title'),
                    text: translate('my_work.tasks.text'),
                    attachTo: { element: '#widget_wrapper_todos', on: 'top' }
                },
                {
                    id: "my-todo-widget2",
                    title: translate('my_work.group.title'),
                    text: translate('my_work.group.text'),
                    attachTo: { element: '#yourToDoContainer > .clear', on: 'bottom' }
                },
                {
                    id: "my-todo-widget4",
                    title: translate('my_work.sort.title'),
                    text: translate('my_work.sort.text'),
                    attachTo: { element: '#yourToDoContainer .sortable-item:first-child', on: 'bottom' }
                },
                {
                    id: "my-todo-widget-timer",
                    title: translate('my_work.timer.title'),
                    text: translate('my_work.timer.text'),
                    attachTo: { element: '#yourToDoContainer .sortable-item:first-child .timerContainer', on: 'bottom' }
                },
                {
                    id: "my-todo-widget-complete",
                    title: translate('my_work.complete.title'),
                    text: translate('my_work.complete.text'),
                    attachTo: { element: '#yourToDoContainer .sortable-item:first-child .statusDropdown', on: 'bottom' }
                },
                {
                    id: "my-todo-widget-add",
                    title: translate('my_work.add.title'),
                    text: translate('my_work.add.text'),
                    attachTo: { element: '#yourToDoContainer .fa-circle-plus', on: 'bottom' }
                },
                {
                    id: "finish",
                    title: translate('complete.title'),
                    text: linkedText('my_work.finish.text'),
                },
            ],
            'projectDashboard': [
                {
                    id: "left-nav",
                    title: translate('project.menu.title'),
                    text: translate('project.menu.text'),
                    attachTo: { element: '.leftmenu ul', on: 'left' }
                },
                {
                    id: 'project-selector',
                    title: translate('project.selector.title'),
                    text: translate('project.selector.text'),
                    attachTo: { element: '.bigProjectSelector', on: 'bottom' }
                },
                {
                    id: 'project-checklist',
                    title: translate('project.checklist.title'),
                    text: translate('project.checklist.text'),
                    attachTo: { element: '#progressForm', on: 'bottom' }
                },
                {
                    id: 'project-status',
                    title: translate('project.status.title'),
                    text: translate('project.status.text'),
                    attachTo: { element: '.project-updates', on: 'left' }
                },
                {
                    id: 'project-progress',
                    title: translate('project.progress.title'),
                    text: translate('project.progress.text'),
                    attachTo: { element: '.project-progress', on: 'left' }
                },
                {
                    id: 'latest-tasks',
                    title: translate('project.latest.title'),
                    text: translate('project.latest.text'),
                    attachTo: { element: '.latest-todos', on: 'left' }
                },
                {
                    id: 'teams',
                    title: translate('project.team.title'),
                    text: translate('project.team.text'),
                    attachTo: { element: '.team-container', on: 'top' }
                },
                {
                    id: 'finished',
                    title: translate('complete.title'),
                    text: linkedText('project.finish.text'),
                }
            ],
            'kanbanBoard': [
                {
                    id: 'kanban-overview',
                    title: translate('kanban.overview.title'),
                    text: translate('kanban.overview.text'),
                    attachTo: { element: '.kanban-board-wrapper', on: 'top' }
                },
                {
                    id: 'kanban-columns',
                    title: translate('kanban.workflow.title'),
                    text: translate('kanban.workflow.text'),
                    attachTo: { element: '.column', on: 'right' }
                },
                {
                    id: 'kanban-columns2',
                    title: translate('kanban.columns.title'),
                    text: translate('kanban.columns.text'),
                    attachTo: { element: '.column .widgettitle .inlineDropDownContainer', on: 'right' }
                },
                {
                    id: 'kanban-filter',
                    title: translate('kanban.filter.title'),
                    text: translate('kanban.filter.text'),
                    attachTo: { element: '.filterWrapper > .btn', on: 'bottom' }
                },
                {
                    id: 'kanban-group',
                    title: translate('kanban.swimlanes.title'),
                    text: translate('kanban.swimlanes.text'),
                    attachTo: { element: '.filterWrapper > .btn-group', on: 'bottom' }
                },
                {
                    id: 'kanban-sprints',
                    title: translate('kanban.sprints.title'),
                    text: translate('kanban.sprints.text'),
                    attachTo: { element: '.pageheader .dropdown', on: 'bottom' }
                },
                {
                    id: 'kanban-congrats',
                    title: translate('complete.title'),
                    text: linkedText('kanban.finish.text'),
                }
            ],
            'milestoneView': [
                {
                    id: 'milestone-overview',
                    title: translate('milestones.overview.title'),
                    text: translate('milestones.overview.text'),
                    attachTo: { element: '.gantt-wrapper', on: 'top' }
                },
                {
                    id: 'milestone-drag',
                    title: translate('milestones.drag.title'),
                    text: translate('milestones.drag.text'),
                    attachTo: { element: '.gantt-wrapper', on: 'top' }
                },
                {
                    id: 'milestone-filter',
                    title: translate('milestones.filter.title'),
                    text: translate('milestones.filter.text'),
                    attachTo: { element: '.filterWrapper > .btn', on: 'bottom' }
                },
                {
                    id: 'milestone-timeframes',
                    title: translate('milestones.timeframes.title'),
                    text: translate('milestones.timeframes.text'),
                    attachTo: { element: '.col-md-4 .pull-right', on: 'bottom' }
                },
                {
                    id: 'milestone-congrats',
                    title: translate('complete.title'),
                    text: linkedText('milestones.finish.text'),
                },
            ],
            'goalsView': [
                {
                    id: 'goals-overview',
                    title: translate('goals.overview.title'),
                    text: translate('goals.overview.text'),
                },
                {
                    id: 'goal-parts',
                    title: translate('goals.parts.title'),
                    text: translate('goals.parts.text'),
                    attachTo: { element: '.ticketBox', on: 'top' }
                },
                {
                    id: 'goal-progress',
                    title: translate('goals.progress.title'),
                    text: translate('goals.progress.text'),
                    attachTo: { element: '.ticketBox > .row > .col-md-12 .progress', on: 'bottom' }
                },
                {
                    id: 'milestone-connection',
                    title: translate('goals.connection.title'),
                    text: translate('goals.connection.text'),
                    attachTo: { element: '.ticketBox.fixed', on: 'bottom' }
                },
                {
                    id: 'milestone-congrats',
                    title: translate('complete.title'),
                    text: linkedText('goals.finish.text'),
                },
            ]
        };

        return tourDefinitions[tourName] || [];
    };

    /**
     * Build a tour from a definition
     * @param {string} tourName - The name of the tour to build
     * @returns {Object} - Configured Shepherd tour object
     */
    var buildTour = function(tourName) {
        const tour = createTour(tourName);
        const steps = getTourDefinition(tourName);

        steps.forEach((step, index) => {
            const isFirst = index === 0;
            const isLast = index === steps.length - 1;

            // Configure buttons based on position in tour
            const buttons = [];

            if (!isFirst) {
                buttons.push({
                    text: leantime.i18n.__("tour.back"),
                    classes: 'shepherd-button-secondary',
                    action: tour.back
                });
            }

            if (isLast) {
                buttons.push({
                    text: leantime.i18n.__("tour.finish"),
                    action: function() {
                        registerTourCompletion(tourName);
                        confetti();
                        tour.complete();
                    }
                });
            } else {
                buttons.push({
                    text: leantime.i18n.__("tour.next"),
                    action: tour.next
                });
            }

            // Add cancel button for all steps
            if (!isLast) {
                buttons.unshift({
                    text: leantime.i18n.__("tour.cancel"),
                    classes: 'shepherd-button-secondary',
                    action: tour.cancel
                });
            }

            // Add the step to the tour
            tour.addStep({
                ...step,
                buttons: buttons
            });
        });

        // Add event handlers
        tour.on('complete', function() {
            registerTourCompletion(tourName);
        });

        return tour;
    };

    /**
     * Start a specific tour
     * @param {string} tourName - The name of the tour to start
     */
    var startTour = function(tourName) {
        const tour = buildTour(tourName);
        tour.start();
        return tour;
    };

    return {
        createTour: createTour,
        buildTour: buildTour,
        startTour: startTour,
        getTourDefinition: getTourDefinition
    };
})();
