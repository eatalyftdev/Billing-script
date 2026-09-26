{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" role="form" action="{$_url}paymentgateway/tranzak">
    <input type="hidden" name="csrf_token" value="{$csrf_token}">
    <div class="row">
        <div class="col-sm-12 col-md-12">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">Tranzak Payment Gateway</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label class="col-md-2 control-label">Environment</label>
                        <div class="col-md-6">
                            <select class="form-control" name="tranzak_env" id="tranzak_env">
                                <option value="sandbox" {if $_c['tranzak_env'] != 'production'}selected{/if}
                                    >Sandbox (SAND_ keys)</option>
                                <option value="production" {if $_c['tranzak_env'] == 'production'}selected{/if}
                                    >Production (PROD_ keys)</option>
                            </select>
                            <small class="form-text text-muted">Test against Sandbox first. The App Key prefix must
                                match the selected environment, otherwise the gateway refuses to run.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Payment Mode</label>
                        <div class="col-md-6">
                            <select class="form-control" name="tranzak_payment_mode" id="tranzak_payment_mode">
                                <option value="wallet" {if $_c['tranzak_payment_mode'] != 'redirect'}selected{/if}
                                    >Mobile Money (MTN / Orange direct charge)</option>
                                <option value="redirect" {if $_c['tranzak_payment_mode'] == 'redirect'}selected{/if}
                                    >Web Redirect (card / web payment)</option>
                            </select>
                            <small class="form-text text-muted">Mobile Money pushes a USSD prompt straight to the
                                customer's phone. Web Redirect sends the customer to the Tranzak payment page, which
                                also allows cards.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">App ID</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="tranzak_app_id" name="tranzak_app_id"
                                value="{$_c['tranzak_app_id']}" autocomplete="off">
                            <a href="https://developer.tranzak.me" target="_blank" class="help-block">
                                https://developer.tranzak.me</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">App Key</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="tranzak_app_key" name="tranzak_app_key"
                                value="{$_c['tranzak_app_key']}" autocomplete="new-password">
                            <small class="form-text text-muted">Leave blank to keep the stored key. Sandbox keys start
                                with SAND_, production keys with PROD_.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Webhook Auth Key</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="tranzak_webhook_authkey"
                                name="tranzak_webhook_authkey" value="{$_c['tranzak_webhook_authkey']}"
                                autocomplete="new-password">
                            <small class="form-text text-muted">The shared key configured on the Tranzak dashboard
                                webhook. Leave blank to keep the stored value. This is a sanity check only, the
                                payment status is always re-verified against Tranzak before a voucher is activated.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary waves-effect waves-light"
                                type="submit">{$_L['Save']}</button>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">Setup</div>
                        <div class="panel-body">
                            <p><strong>1.</strong> Enable this gateway on the
                                <a href="{$_url}paymentgateway">Payment Gateway</a> page.</p>
                            <p><strong>2.</strong> Create a webhook in the Tranzak dashboard for event type
                                <code>REQUEST.COMPLETED</code>, pointing at:</p>
                            <pre>{$_url}callback/tranzak</pre>
                            <small class="form-text text-muted">If the site uses canonical URLs, use
                                <code>{$_domain}/callback/tranzak</code> instead.</small>
                            <p><strong>3.</strong> Set the same auth key in the webhook and in the field above.</p>
                            <p><strong>4.</strong> The customer needs a valid Cameroon mobile number
                                (MTN or Orange) on their profile for the Mobile Money mode.</p>
                            <small class="form-text text-muted">Set Telegram Bot to get any error and
                                notification</small>
                        </div>
                    </div>

                    <pre>/ip hotspot walled-garden
add dst-host=pay.tranzak.me
add dst-host=dsapi.tranzak.me</pre>
                </div>
            </div>

        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
