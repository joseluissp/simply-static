import {Card, CardBody} from '@wordpress/components';
import {useContext, useEffect, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {SettingsContext} from '../context/SettingsContext';

const {__} = wp.i18n;

const Link = ({href, children}) => <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>;

/**
 * "Where does my static site live?" card for the start screen.
 * Only shown when Cloudflare is the delivery method.
 */
export default function SiteLocation() {
    const {isRunning} = useContext(SettingsContext);
    const [info, setInfo] = useState(null);

    // Refresh when an export finishes: that is when the address and date change.
    useEffect(() => {
        if (isRunning) {
            return;
        }
        apiFetch({path: '/simplystatic/v1/cloudflare'}).then(setInfo).catch(() => setInfo(null));
    }, [isRunning]);

    if (!info || !info.enabled) {
        return null;
    }

    const address = info.domain ? 'https://' + info.domain : info.url;

    return (
        <Card className="ss-site-location" style={{marginBottom: "20px"}}>
            <CardBody>
                <div style={{display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap'}}>
                    <div>
                        <strong>{__('Your static site lives on Cloudflare', 'simply-static')}</strong>
                        <div>
                            {address
                                ? <Link href={address}>{address}</Link>
                                : <em>{__('Not deployed yet. Run a full export to publish it.', 'simply-static')}</em>}
                        </div>
                        <small>
                            {__('Worker:', 'simply-static')} {info.worker_name}
                            {info.deployed_at > 0 && <> · {__('Last deployed:', 'simply-static')} {new Date(info.deployed_at * 1000).toLocaleString()}</>}
                        </small>
                    </div>
                    {info.dashboard && <Link href={info.dashboard}>{__('Open in Cloudflare', 'simply-static')}</Link>}
                </div>
            </CardBody>
        </Card>
    );
}
