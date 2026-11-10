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
                        label={__('Custom domain (optional)', 'simply-static')}
                        value={form.domain || ''}
                        placeholder="www.example.com"
                        onChange={set('domain')}
                        help={__('The domain must already be on Cloudflare and must not have a CNAME record for this host.', 'simply-static')}
                    />
                    <ToggleControl
                        label={__('Auto-publish', 'simply-static')}
                        help={__('Export and deploy about a minute after content is published, updated or removed.', 'simply-static')}
                        checked={!!form.auto_publish}
                        onChange={set('auto_publish')}
                    />
                    <ToggleControl
                        label={__('Forms', 'simply-static')}
                        help={__('Make WordPress forms work on the static site. Submissions are stored in Cloudflare D1 and protected by Turnstile.', 'simply-static')}
                        checked={!!form.forms}
                        onChange={set('forms')}
                    />
                    {!!form.forms &&
                        <TextControl
                            type="url"
                            label={__('Forms webhook (optional)', 'simply-static')}
                            value={form.webhook_url || ''}
                            onChange={set('webhook_url')}
                            help={__('Every submission is also sent here as JSON (Zapier, Make, n8n…).', 'simply-static')}
                        />
                    }

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
