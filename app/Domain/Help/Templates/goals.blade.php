<div class="center padding-lg">

    <div class="row">
        <div class="col-md-12">

            <x-global::undrawSvg
                image="undraw_goals_re_lu76.svg"
                maxWidth="auto"
                headlineSize="var(--font-size-xxxl)"
                maxheight="auto"
                height="250px"
                headline="{{ __('help.intro.goals.title') }}"
            ></x-global::undrawSvg>
        </div>
    </div>

    <div class="row ">
        <div class="col-md-12" style="font-size:var(--font-size-l);">
            <br />
            <div id="firstLoginContent">
                <p><br />{!! __('help.intro.goals.text') !!}</p><br />
            </div>
            <br /><br />
            <div class="row">
                <div class="col-md-12 tw-text-center">
                    <x-global::forms.button tag="a" link="javascript:void(0)" contentRole="tertiary" onclick="leantime.helperController.closeModal()">{{ __('help.intro.explore') }}</x-global::forms.button>
                    <x-global::forms.button tag="a" link="javascript:void(0)" contentRole="primary" onclick="leantime.helperController.closeModal(); leantime.helperController.startGoalTour();">{{ __("buttons.start_tour") }} <i class="fa-solid fa-arrow-right"></i></x-global::forms.button>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12 tw-text-center">
                    <form hx-post="{{ BASE_URL }}/help/helperModal/dontShowAgain" hx-trigger="change" hx-swap="none">
                        <label class="tw-text-sm tw-mt-sm" >
                            <input type="hidden" name="modalId" value="goals" />
                            <input type="checkbox" id="dontShowAgain" name="hidePermanently"  style="margin-top:-2px;">
                            {{ __('help.intro.hide') }}
                        </label>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
