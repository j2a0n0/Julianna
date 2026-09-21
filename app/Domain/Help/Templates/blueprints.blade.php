<div class="center padding-lg">

    <div class="row">
        <div class="col-md-12">
            <div style='width:50%' class='svgContainer'>
                {!! file_get_contents(ROOT . '/dist/images/svg/undraw_design_data_khdb.svg') !!}
            </div>
            <h1>{{ __('help.intro.blueprints.title') }}</h1><br />
            <p>{!! __('help.intro.blueprints.text') !!}</p>
            <br /><br />
        </div>
    </div>


    <div class="row">
        <div class="col-md-12">

            <x-global::forms.button tag="a" link="{{ BASE_URL }}/valuecanvas/showCanvas" contentRole="primary">{{ __('help.intro.blueprints.create') }}</x-global::forms.button><br />

        </div>
    </div>


</div>
