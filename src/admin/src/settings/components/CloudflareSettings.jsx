import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Flex,
    Notice,
    SelectControl,
    Spinner,
    TextControl,
    TextareaControl,
    ToggleControl,
    __experimentalSpacer as Spacer,
} from '@wordpress/components';
import {useEffect, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const {__} = wp.i18n;

const PATH = '/simplystatic/v1/cloudflare';

// Plain anchor: ExternalLink is a flex item and breaks inline text.
const Link = ({href, children}) => <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;

/** The last published versions, each one a click away from being live again. */
function Versions({enabled}) {
    const [versions, setVersions] = useState(null);
    const [busy, setBusy] = useState('');
    const [message, setMessage] = useState(null);

    const load = () => apiFetch({path: PATH + '/versions'}).then((rows) => setVersions(Array.isArray(rows) ? rows : [])).catch(() => setVersions([]));

    useEffect(() => {
        if (enabled) {
            load();
        }
    }, [enabled]);

    if (!enabled || !versions || !versions.length) {
        return null;
    }

    const restore = (version) => {
        if (!window.confirm(__('Make this version the live site again? Newer content stays in WordPress and will be published on your next export.', 'simply-static'))) {
            return;
        }
        setBusy(version.id);
        setMessage(null);
        apiFetch({path: PATH + '/rollback', method: 'POST', data: {version: version.id}})
            .then(() => {
                setMessage({status: 'success', text: __('Done. The selected version is live.', 'simply-static')});
                return load();
            })
            .catch((e) => setMessage({status: 'error', text: e?.message || __('Something went wrong.', 'simply-static')}))
            .finally(() => setBusy(''));
    };

    return (
        <>
            <Spacer margin={5}/>
            <Card>
                <CardHeader><b>{__('Published versions', 'simply-static')}</b></CardHeader>
                <CardBody>
                    {message && <Notice status={message.status} isDismissible={false}><p>{message.text}</p></Notice>}
                    <table className="widefat striped">
                        <tbody>
                        {versions.map((v) => (
                            <tr key={v.id}>
                                <td>#{v.number}</td>
                                <td>{v.created ? new Date(v.created).toLocaleString() : ''}</td>
                                <td>{v.message}</td>
                                <td style={{textAlign: 'right'}}>
                                    {v.active
                                        ? <b>{__('Live', 'simply-static')}</b>
                                        : <Button variant="secondary" isBusy={busy === v.id} disabled={!!busy} onClick={() => restore(v)}>
                                            {__('Go back to this version', 'simply-static')}
                                        </Button>}
                                </td>
                            </tr>
                        ))}
                        </tbody>
                    </table>
                </CardBody>
            </Card>
        </>
    );
}

/**
 * Cloudflare delivery settings. Lives in its own option and REST route (not the main
 * settings object), so it saves with its own button.
 */
export default function CloudflareSettings() {
    const [data, setData] = useState(null);
    const [form, setForm] = useState({});
    const [token, setToken] = useState('');
    const [saving, setSaving] = useState(false);
    const [result, setResult] = useState(null);
    const [submissions, setSubmissions] = useState([]);
    const [loadError, setLoadError] = useState('');

    const apply = (payload) => {
        setData(payload);
        setForm(payload.settings);
    };

    useEffect(() => {
        apiFetch({path: PATH}).then(apply).catch((e) => setLoadError(e?.message || __('Could not load Cloudflare settings.', 'simply-static')));
    }, []);

    useEffect(() => {
        if (data && data.enabled && data.token_set && data.forms_ready) {
            apiFetch({path: PATH + '/submissions'}).then((rows) => setSubmissions(Array.isArray(rows) ? rows : [])).catch(() => {});
        }
    }, [data && data.forms_ready, data && data.token_set]);

    const set = (key) => (value) => setForm({...form, [key]: value});

    const save = () => {
        setSaving(true);
        setResult(null);
        apiFetch({path: PATH, method: 'POST', data: {...form, token}})
            .then((payload) => {
                apply(payload);
                setToken('');
                setResult({error: payload.error, notes: payload.notes});
            })
            .catch((e) => setResult({error: e?.message || __('Something went wrong.', 'simply-static'), notes: []}))
            .finally(() => setSaving(false));
    };

    if (loadError) {
        return <Notice status="error" isDismissible={false}>{loadError}</Notice>;
    }
    if (!data) {
        return <Spinner/>;
    }

    const locked = (key) => data.locked.includes(key);

    return (
        <>
            <Card>
                <CardHeader>
                    <b>{__('Cloudflare', 'simply-static')}</b>
                </CardHeader>
                <CardBody>
                    <p>
                        {__('Your static site is deployed to Cloudflare Workers. Paste an API token and everything else is detected.', 'simply-static')}
                    </p>

                    {data.url &&
                        <Notice status="success" isDismissible={false}>
                            <p>
                                {__('Live at:', 'simply-static')}{' '}
                                <Link href={data.url}>{data.url}</Link>
                                {data.domain && <> · <Link href={'https://' + data.domain}>{data.domain}</Link></>}
                            </p>
                        </Notice>
                    }
                    {data.domain_error &&
                        <Notice status="warning" isDismissible={false}><p>{data.domain_error}</p></Notice>
                    }

                    <TextControl
                        type="password"
                        label={__('API token', 'simply-static')}
                        value={token}
                        autoComplete="off"
                        disabled={locked('token')}
                        placeholder={data.token_set ? '••••••••••••' : ''}
                        onChange={setToken}
                        help={
                            <>
                                {data.token_set
                                    ? __('A token is stored. Leave blank to keep it.', 'simply-static')
                                    : __('Needed to deploy. It never leaves your server.', 'simply-static')}
                                {' '}
                                <Link href={data.token_url}>{__('Create a token in Cloudflare', 'simply-static')}</Link>
                                {' · '}
                                <Link href="https://dash.cloudflare.com/profile/api-tokens">{__('Manage tokens', 'simply-static')}</Link>
                            </>
                        }
                    />
                    <TextControl
                        label={__('Final domain (optional)', 'simply-static')}
                        value={form.domain || ''}
                        placeholder="www.example.com"
                        onChange={set('domain')}
                        help={__('Leave it empty while you test: the site lives at its workers.dev address. When the domain is in your Cloudflare account and its nameservers point to Cloudflare, enter it here and save: the site is attached to the domain and published again with the final addresses. Until then your current site keeps serving the domain.', 'simply-static')}
                    />
                    {!!form.domain &&
                        <ToggleControl
                            label={__('Turn off the workers.dev address once the domain is live', 'simply-static')}
                            help={__('Avoids having the same site on two addresses.', 'simply-static')}
                            checked={!!form.disable_workers_dev}
                            onChange={set('disable_workers_dev')}
                        />
                    }
                    <ToggleControl
                        label={__('Auto-publish', 'simply-static')}
                        help={__('Publish about a minute after content is published, updated or removed.', 'simply-static')}
                        checked={!!form.auto_publish}
                        onChange={set('auto_publish')}
                    />
                    {!!form.auto_publish &&
                        <SelectControl
                            label={__('What auto-publish exports', 'simply-static')}
                            value={form.autopublish_type || 'update'}
                            options={[
                                {label: __('Changes only (fast)', 'simply-static'), value: 'update'},
                                {label: __('Full site', 'simply-static'), value: 'export'},
                            ]}
                            onChange={set('autopublish_type')}
                            help={__('Changes only falls back to a full export by itself when something that affects every page changes (menus, theme, plugins, domain, Hide WP).', 'simply-static')}
                        />
                    }
                    <ToggleControl
                        label={__('Forms', 'simply-static')}
                        help={__('Make WordPress forms work on the static site. Submissions are stored in Cloudflare D1 and protected by Turnstile.', 'simply-static')}
                        checked={!!form.forms}
                        onChange={set('forms')}
                    />
                    {!!form.forms &&
                        <>
                            <ToggleControl
                                label={__('Email me every form message', 'simply-static')}
                                help={data.email && data.email.note}
                                checked={!!form.notify}
                                onChange={set('notify')}
                            />
                            {!!form.notify &&
                                <TextControl
                                    type="email"
                                    label={__('Send messages to', 'simply-static')}
                                    value={form.notify_email || ''}
                                    placeholder={data.email && data.email.to ? data.email.to : ''}
                                    onChange={set('notify_email')}
                                    help={__('Empty means the WordPress admin email.', 'simply-static')}
                                />
                            }
                            <TextControl
                                type="url"
                                label={__('Forms webhook (optional)', 'simply-static')}
                                value={form.webhook_url || ''}
                                onChange={set('webhook_url')}
                                help={__('Every submission is also sent here as JSON (Zapier, Make, n8n…).', 'simply-static')}
                            />
                        </>
                    }
                    <ToggleControl
                        label={__('Search', 'simply-static')}
                        help={__('Adds a search page and points the theme’s search boxes to it. It works in the browser: no server needed.', 'simply-static')}
                        checked={!!form.search}
                        onChange={set('search')}
                    />
                    <ToggleControl
                        label={__('Hide WordPress', 'simply-static')}
                        help={__('Renames wp-content and wp-includes in the published site and removes the generator, version and other WordPress markers. Fine-tune it in the Hide WP page.', 'simply-static')}
                        checked={!!form.hide_wp}
                        onChange={set('hide_wp')}
                    />

                    <details>
                        <summary>{__('Advanced', 'simply-static')}</summary>
                        <Spacer margin={3}/>
                        <TextControl
                            label={__('Account ID', 'simply-static')}
                            value={form.account_id || ''}
                            disabled={locked('account_id')}
                            onChange={set('account_id')}
                            help={__('Detected from the token. Only needed if your token can see several accounts.', 'simply-static')}
                        />
                        <TextControl
                            label={__('Worker name', 'simply-static')}
                            value={form.worker_name || ''}
                            disabled={locked('worker_name')}
                            placeholder={data.default_name}
                            onChange={set('worker_name')}
                            help={__('Lowercase letters, numbers and dashes. Created on the first deploy.', 'simply-static')}
                        />
                        <TextareaControl
                            label={__('Extra redirects', 'simply-static')}
                            value={form.extra_redirects || ''}
                            rows={4}
                            onChange={set('extra_redirects')}
                            help={__('One per line: /old /new 301. Redirects from the Redirection plugin are included automatically.', 'simply-static')}
                        />
                        <TextareaControl
                            label={__('Extra headers', 'simply-static')}
                            value={form.extra_headers || ''}
                            rows={4}
                            onChange={set('extra_headers')}
                            help={__('Cloudflare _headers syntax. Security and cache headers are added automatically.', 'simply-static')}
                        />
                        <SelectControl
                            label={__('Trailing slashes', 'simply-static')}
                            value={form.html_handling}
                            options={['auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none'].map((v) => ({label: v, value: v}))}
                            onChange={set('html_handling')}
                        />
                        <SelectControl
                            label={__('When a page is not found', 'simply-static')}
                            value={form.not_found_handling}
                            options={['404-page', 'single-page-application', 'none'].map((v) => ({label: v, value: v}))}
                            onChange={set('not_found_handling')}
                        />
                    </details>

                    <Spacer margin={4}/>
                    {result && result.error &&
                        <Notice status="error" isDismissible={false}><p>{result.error}</p></Notice>
                    }
                    {result && !result.error && (result.notes || []).map((note, i) =>
                        <Notice key={i} status="success" isDismissible={false}><p>{note}</p></Notice>
                    )}
                    {data.warnings.map((w, i) =>
                        <Notice key={'w' + i} status="warning" isDismissible={false}><p>{w}</p></Notice>
                    )}
                    <Flex justify="flex-start">
                        <Button variant="primary" isBusy={saving} disabled={saving} onClick={save}>
                            {__('Save and connect', 'simply-static')}
                        </Button>
                        {data.dashboard &&
                            <Link href={data.dashboard}>{__('Open in Cloudflare', 'simply-static')}</Link>
                        }
                    </Flex>
                </CardBody>
            </Card>

            <Versions enabled={data.token_set && !!data.deployed_at}/>

            {submissions.length > 0 &&
                <>
                    <Spacer margin={5}/>
                    <Card>
                        <CardHeader><b>{__('Form submissions', 'simply-static')}</b></CardHeader>
                        <CardBody>
                            <table className="widefat striped">
                                <thead>
                                <tr>
                                    <th>{__('Date (UTC)', 'simply-static')}</th>
                                    <th>{__('Page', 'simply-static')}</th>
                                    <th>{__('Data', 'simply-static')}</th>
                                </tr>
                                </thead>
                                <tbody>
                                {submissions.map((row) => {
                                    let fields = {};
                                    try {
                                        fields = JSON.parse(row.data);
                                    } catch (e) {
                                        fields = {};
                                    }
                                    return (
                                        <tr key={row.id}>
                                            <td>{row.created_at}</td>
                                            <td>{row.page}</td>
                                            <td>
                                                <pre style={{margin: 0, whiteSpace: 'pre-wrap'}}>
                                                    {Object.entries(fields).map(([k, v]) => k + ': ' + (Array.isArray(v) ? v.join(', ') : v)).join('\n')}
                                                </pre>
                                            </td>
                                        </tr>
                                    );
                                })}
                                </tbody>
                            </table>
                        </CardBody>
                    </Card>
                </>
            }
        </>
    );
}
