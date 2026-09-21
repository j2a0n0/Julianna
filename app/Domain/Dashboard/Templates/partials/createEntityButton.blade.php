@if ($login::userIsAtLeast($roles::$editor))
    <div class="btn-group pull-left" style="margin-right:5px;">
        <button class="btn btn-primary dropdown-toggle" type="button" data-toggle="dropdown"><?=$tpl->__("links.new_with_icon") ?> <span class="caret"></span></button>
        <ul class="dropdown-menu">
            <li><a href="#/tickets/newTicket">{{ __('buttons.add_todo') }}</a></li>
            <li><a href="#/tickets/editMilestone">{{ __('buttons.add_milestone') }}</a></li>

        </ul>
    </div>
@endif
