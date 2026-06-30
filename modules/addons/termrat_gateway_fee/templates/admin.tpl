<style>
.termrat-gateway-fee-grid {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(320px, 1fr);
    gap: 16px;
}
.termrat-gateway-fee-result {
    margin-top: 12px;
}
@media (max-width: 900px) {
    .termrat-gateway-fee-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="termrat-gateway-fee-admin">
    <h2>TermRat Gateway Fee</h2>
    {{notice}}

    <div class="termrat-gateway-fee-grid">
        <div class="panel panel-default">
            <div class="panel-heading"><strong>Current Configuration</strong></div>
            <div class="panel-body">
                <table class="table table-condensed table-striped">
                    <tbody>
                    {{config_rows}}
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading"><strong>Dry-run / Resync Invoice</strong></div>
            <div class="panel-body">
                <form method="post" action="{{modulelink}}">
                    <input type="hidden" name="token" value="{{token}}">
                    <div class="form-group">
                        <label for="termrat-gateway-fee-invoice-id">Invoice ID</label>
                        <input class="form-control" id="termrat-gateway-fee-invoice-id" name="invoice_id" type="number" min="1" step="1" required>
                    </div>
                    <button class="btn btn-default" type="submit" name="termrat_gateway_fee_action" value="dry_run">Dry-run</button>
                    <button class="btn btn-primary" type="submit" name="termrat_gateway_fee_action" value="resync">Resync</button>
                </form>
                {{dry_run_rows}}
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Recent Fee Records</strong></div>
        <div class="panel-body">
            <div class="table-responsive">
                <table class="table table-condensed table-striped">
                    <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Gateway</th>
                        <th>Base Amount</th>
                        <th>Fee Amount</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                    </tr>
                    </thead>
                    <tbody>
                    {{fee_rows}}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
