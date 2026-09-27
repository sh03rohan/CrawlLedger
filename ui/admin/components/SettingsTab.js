import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { api } from '../api';
import { Panel, Segmented, SaveBar } from './ui';

function Choice( { name, value, current, title, desc, onChange } ) {
	const id = `clg-${ name }-${ value }`;
	return (
		<label htmlFor={ id } className={ `clg-choice ${ current === value ? 'is-selected' : '' }` }>
			<input id={ id } type="radio" name={ name } value={ value } checked={ current === value } onChange={ () => onChange( value ) } />
			<strong>{ title }</strong>
			<span className="clg-muted">{ desc }</span>
		</label>
	);
}

export default function SettingsTab() {
	const [ saved, setSaved ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( '' );

	const pick = ( s ) => ( { retention_days: s.retention_days, ip_mode: s.ip_mode, rate_cap_per_minute: s.rate_cap_per_minute, keep_data_on_uninstall: s.keep_data_on_uninstall, verification_enabled: s.verification_enabled } );
	useEffect( () => {
		api.get( '/settings' ).then( ( s ) => {
			setSaved( { ...pick( s ), retention_choices: s.retention_choices } ); setForm( pick( s ) );
		} );
	}, [] );
	if ( ! form || ! saved ) {
		return <div className="clg-loading"><Spinner /></div>;
	}
	const dirty = JSON.stringify( form ) !== JSON.stringify( pick( saved ) );

	const save = async () => {
		setSaving( true );
		setMessage( '' );
		try {
			const s = await api.post( '/settings', form );
			setSaved( { ...pick( s ), retention_choices: s.retention_choices } );
			setForm( pick( s ) );
			setMessage( __( 'Settings saved.', 'crawlledger-ai-crawler-log' ) );
		} catch ( e ) {
			setMessage( e.message );
		} finally {
			setSaving( false );
		}
	};

	const retention = [ 7, 30, 90 ].map( ( d ) => ( {
		value: d,
		label: sprintf(
			/* translators: %d: days */
			__( '%d days', 'crawlledger-ai-crawler-log' ), d ) + ( saved.retention_choices.includes( d ) ? '' : ' 🔒' ),
		disabled: ! saved.retention_choices.includes( d ),
		title: saved.retention_choices.includes( d ) ? '' : __( 'Longer retention is part of the add-on', 'crawlledger-ai-crawler-log' ),
	} ) );

	return (
		<div>
			<Panel title={ __( 'Logging', 'crawlledger-ai-crawler-log' ) }>
				<div className="clg-field">
					<span className="clg-label">{ __( 'Keep individual visits for', 'crawlledger-ai-crawler-log' ) }</span>
					<Segmented value={ form.retention_days } options={ retention } onChange={ ( v ) => setForm( { ...form, retention_days: v } ) } label={ __( 'Retention', 'crawlledger-ai-crawler-log' ) } />
					<span className="clg-muted">{ __( 'Daily totals per crawler are kept indefinitely regardless; only the per-request rows expire.', 'crawlledger-ai-crawler-log' ) }</span>
				</div>
				<div className="clg-field">
					<span className="clg-label">{ __( 'IP address storage', 'crawlledger-ai-crawler-log' ) }</span>
					<div className="clg-choices">
						<Choice name="ip" value="truncated" current={ form.ip_mode } onChange={ ( v ) => setForm( { ...form, ip_mode: v } ) } title={ __( 'Truncated', 'crawlledger-ai-crawler-log' ) } desc={ __( 'Keeps /24 (IPv4) or /48 (IPv6). Default; enough to tell networks apart.', 'crawlledger-ai-crawler-log' ) } />
						<Choice name="ip" value="hashed" current={ form.ip_mode } onChange={ ( v ) => setForm( { ...form, ip_mode: v } ) } title={ __( 'Salted hash', 'crawlledger-ai-crawler-log' ) } desc={ __( 'One-way, per-site salt. Repeat visits still correlate; the address cannot be recovered.', 'crawlledger-ai-crawler-log' ) } />
						<Choice name="ip" value="full" current={ form.ip_mode } onChange={ ( v ) => setForm( { ...form, ip_mode: v } ) } title={ __( 'Full address', 'crawlledger-ai-crawler-log' ) } desc={ __( 'Personal data under GDPR — mention it in your privacy policy.', 'crawlledger-ai-crawler-log' ) } />
					</div>
					<span className="clg-muted">{ __( 'Verification always runs on the real address before it is reduced for storage.', 'crawlledger-ai-crawler-log' ) }</span>
				</div>
				<div className="clg-field clg-field--inline">
					<TextControl type="number" min={ 10 } max={ 100000 } label={ __( 'Per-crawler ceiling, visits per minute', 'crawlledger-ai-crawler-log' ) } value={ form.rate_cap_per_minute } onChange={ ( v ) => setForm( { ...form, rate_cap_per_minute: parseInt( v || '0', 10 ) } ) } __nextHasNoMarginBottom />
					<span className="clg-muted">{ __( 'Above this, visits are still counted in daily totals but per-request rows are not stored, so a flood cannot turn the log into an amplifier.', 'crawlledger-ai-crawler-log' ) }</span>
				</div>
			</Panel>

			<Panel title={ __( 'Crawler verification', 'crawlledger-ai-crawler-log' ) } aside={ form.verification_enabled ? <span className="clg-ok">{ __( '● on', 'crawlledger-ai-crawler-log' ) }</span> : <span className="clg-muted">{ __( 'off', 'crawlledger-ai-crawler-log' ) }</span> }>
				<p className="clg-blurb">{ __( 'A scraper can put "GPTBot" in its user agent. Verification checks each visit against the IP ranges the vendor publishes and, where the vendor offers one, a forward-confirmed reverse DNS lookup. Without it every visit is logged but recorded as unchecked.', 'crawlledger-ai-crawler-log' ) }</p>
				<ToggleControl
					label={ __( 'Check crawler identity against vendor sources', 'crawlledger-ai-crawler-log' ) }
					help={ __( 'Turning this on lets the plugin fetch the public IP-range files published by OpenAI, Google, Perplexity, Microsoft and Apple once a week, and look up reverse DNS for crawler addresses. Those requests carry only the plugin name and version — no site URL, no visitor data, nothing about your content. Until you turn it on the plugin makes no outbound request at all.', 'crawlledger-ai-crawler-log' ) }
					checked={ !! form.verification_enabled }
					onChange={ ( v ) => setForm( { ...form, verification_enabled: v } ) }
					__nextHasNoMarginBottom
				/>
			</Panel>

			<Panel title={ __( 'Uninstall', 'crawlledger-ai-crawler-log' ) }>
				<ToggleControl label={ __( 'Keep my data when the plugin is deleted', 'crawlledger-ai-crawler-log' ) } help={ form.keep_data_on_uninstall ? __( 'Tables, settings and the log directory survive deletion; only the cron events are removed.', 'crawlledger-ai-crawler-log' ) : __( 'Deleting the plugin removes its tables, settings, cron events and the log directory. This cannot be undone.', 'crawlledger-ai-crawler-log' ) } checked={ form.keep_data_on_uninstall } onChange={ ( v ) => setForm( { ...form, keep_data_on_uninstall: v } ) } __nextHasNoMarginBottom />
			</Panel>

			<SaveBar dirty={ dirty } saving={ saving } message={ message } onSave={ save } onDiscard={ () => {
				setForm( pick( saved ) );
			} } />
		</div>
	);
}
