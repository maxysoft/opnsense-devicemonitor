<div class="content-box">
    <div class="content-box-main">

        <h1 style="padding-left:10px;">
            {{ lang._('Device Monitor') }}
            <small id="settings-version" style="font-size:13px;color:#888;margin-left:5px;"></small>
            <span style="font-weight:normal;font-size:18px;margin-left:8px;">{{ lang._('Settings') }}</span>
        </h1>

        <div class="alert alert-danger hidden" role="alert" id="responseMsg"></div>

        <div class="col-md-12">
            {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_GeneralSettings']) }}
        </div>

        <div class="col-md-12" style="padding-bottom:15px;">
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b>
                <i id="saveAct_progress" class=""></i>
            </button>
            <button class="btn btn-default" id="testEmailAct" type="button">
                {{ lang._('Test email') }}
                <i id="testEmailAct_progress" class=""></i>
            </button>
            <button class="btn btn-default" id="testWebhookAct" type="button">
                {{ lang._('Test webhook') }}
                <i id="testWebhookAct_progress" class=""></i>
            </button>
        </div>

        <div class="col-md-12">
            <table class="table table-condensed" style="max-width:600px;">
                <tr>
                    <td style="width:40%;color:#888;">{{ lang._('Original author') }}</td>
                    <td><a href="https://github.com/hacesoft/opnsense-devicemonitor" target="_blank">Hacesoft</a></td>
                </tr>
                <tr>
                    <td style="color:#888;">{{ lang._('Packaged from') }}</td>
                    <td><a href="https://github.com/maxysoft/opnsense-devicemonitor" target="_blank">maxysoft/opnsense-devicemonitor</a></td>
                </tr>
                <tr>
                    <td style="color:#888;">{{ lang._('License') }}</td>
                    <td>BSD 2-Clause</td>
                </tr>
            </table>
        </div>

    </div>
</div>

<script>
$(document).ready(function () {
    var msg = $("#responseMsg");

    function notify(text, isError) {
        msg.removeClass("hidden alert-danger alert-success")
           .addClass(isError ? "alert-danger" : "alert-success")
           .html(text);
    }

    function busy(id, on) {
        $("#" + id + "_progress").toggleClass("fa fa-spinner fa-pulse", on);
        $("#" + id).prop("disabled", on);
    }

    mapDataToFormUI({'frm_GeneralSettings': "/api/devicemonitor/settings/get"}).done(function () {
        $('.selectpicker').selectpicker('refresh');
    });

    $.getJSON("/api/devicemonitor/config/getversion", function (data) {
        $("#settings-version").text("v" + (data.version || "?"));
    });


    $("#saveAct").click(function () {
        busy("saveAct", true);
        saveFormToEndpoint("/api/devicemonitor/settings/set", 'frm_GeneralSettings', function () {
            ajaxCall("/api/devicemonitor/service/reconfigure", {}, function () {
                busy("saveAct", false);
                notify("{{ lang._('Settings saved and the daemon reloaded.') }}", false);
            });
        }, false, function () {
            busy("saveAct", false);
        });
    });

    // The test uses whatever is stored, so save first to avoid testing a
    // different transport than the one shown in the form.
    $("#testEmailAct").click(function () {
        busy("testEmailAct", true);
        saveFormToEndpoint("/api/devicemonitor/settings/set", 'frm_GeneralSettings', function () {
            ajaxCall("/api/devicemonitor/config/testemail", {}, function (data) {
                busy("testEmailAct", false);
                var ok = data && (data.result === 'sent' || data.result === 'ok');
                notify(ok ? "{{ lang._('Test email sent.') }}"
                          : ((data && data.message) || "{{ lang._('Sending the test email failed.') }}"), !ok);
            });
        }, false, function () {
            busy("testEmailAct", false);
        });
    });

    $("#testWebhookAct").click(function () {
        busy("testWebhookAct", true);
        saveFormToEndpoint("/api/devicemonitor/settings/set", 'frm_GeneralSettings', function () {
            var url = $("#devicemonitor\\.general\\.webhook_url").val();
            ajaxCall("/api/devicemonitor/config/testWebhook", {'webhook_url': url}, function (data) {
                busy("testWebhookAct", false);
                var ok = data && (data.result === 'sent' || data.result === 'ok');
                notify(ok ? "{{ lang._('Test webhook sent.') }}"
                          : ((data && data.message) || "{{ lang._('Sending the test webhook failed.') }}"), !ok);
            });
        }, false, function () {
            busy("testWebhookAct", false);
        });
    });
});
</script>
