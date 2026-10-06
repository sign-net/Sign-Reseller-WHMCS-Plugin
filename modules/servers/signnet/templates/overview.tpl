{* The customer's Sign.net portal on the service Overview: its address and state, the DNS records
   still to create, and what the package includes. Variables come from ClientAreaView. *}
<div class="signnet-overview">
    <h3>{$signnet.portalName|escape}</h3>

    {if $signnet.loadFailed}
        <div class="alert alert-warning">We could not load your portal's details right now. Please try again later.</div>
    {/if}

    <p>
        Status:
        {if $signnet.state eq 'active'}
            <span class="label label-success badge badge-success">{$signnet.stateLabel|escape}</span>
        {elseif $signnet.state eq 'suspended'}
            <span class="label label-danger badge badge-danger">{$signnet.stateLabel|escape}</span>
        {elseif $signnet.state eq 'deleted'}
            <span class="label label-default badge badge-secondary">{$signnet.stateLabel|escape}</span>
        {else}
            <span class="label label-warning badge badge-warning">{$signnet.stateLabel|escape}</span>
        {/if}
    </p>

    {if $signnet.state eq 'setting_up'}
        <p>Your portal is being set up. We will email you when it is ready.</p>
    {elseif $signnet.state eq 'suspended'}
        <p>Your portal is suspended, so nobody can sign in to it. Please contact us.</p>
    {elseif $signnet.state eq 'deleted'}
        <p>This portal has been deleted.</p>
    {/if}

    {if $signnet.hostname}
        <p>
            Address:
            {if $signnet.showLinks}
                <a href="{$signnet.portalUrl|escape}" target="_blank" rel="noopener noreferrer">{$signnet.hostname|escape}</a>
            {else}
                {$signnet.hostname|escape}
            {/if}
        </p>
    {/if}

    {if $signnet.showLinks}
        <p>
            <a class="btn btn-primary" href="{$signnet.portalUrl|escape}" target="_blank" rel="noopener noreferrer">Open portal</a>
            <a class="btn btn-default" href="{$signnet.organisationUrl|escape}" target="_blank" rel="noopener noreferrer">Theme and people</a>
        </p>
        <p class="text-muted">Once signed in, change your portal's colours, logos and people on its Organisation page.</p>
    {/if}

    {if $signnet.dnsRecords}
        <h4>Point your domain at your portal</h4>
        <p>Create these records with your DNS provider. Changes can take a few hours to spread.</p>
        <table class="table table-striped">
            <thead>
                <tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr>
            </thead>
            <tbody>
                {foreach $signnet.dnsRecords as $record}
                    <tr>
                        <td>{$record.type|escape}</td>
                        <td><code>{$record.name|escape}</code></td>
                        <td><code>{$record.value|escape}</code></td>
                        <td>
                            <button type="button" class="btn btn-default btn-sm signnet-copy" data-copy="{$record.value|escape}">Copy</button>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
        <form method="post" action="{$signnet.checkAgainUrl|escape}">
            <input type="hidden" name="token" value="{$signnet.csrfToken|escape}">
            <button type="submit" class="btn btn-default">Check again</button>
        </form>
    {/if}

    {if $signnet.packageName}
        <h4>Your package: {$signnet.packageName|escape}</h4>
        {if $signnet.allocated}
            <ul>
                {foreach $signnet.allocated as $item}
                    <li>{$item.item|escape}: {$item.quantity|escape}</li>
                {/foreach}
            </ul>
        {/if}
        {if $signnet.addons}
            <p>Add-ons:</p>
            <ul>
                {foreach $signnet.addons as $addon}
                    <li>{$addon.name|escape} &times; {$addon.quantity|escape}</li>
                {/foreach}
            </ul>
        {/if}
    {/if}
</div>
{literal}
<script>
document.querySelectorAll('.signnet-copy').forEach(function (button) {
    button.addEventListener('click', function () {
        navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
            button.textContent = 'Copied';
        });
    });
});
</script>
{/literal}
