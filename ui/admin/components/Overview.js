import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner, ToggleControl } from '@wordpress/components';
import { api, formatBytes, formatNumber, timeAgo } from '../api';
import { AreaChart, Sparkline, Proportion } from './Charts';
import Coverage from './Coverage';

const TYPE = {
	answer: { label: __( 'Answer', 'crawlledger-ai-crawler-log' ), hint: __( 'Fetches pages to build answers for users in real time.', 'crawlledger-ai-crawler-log' ) },
	search: { label: __( 'Search', 'crawlledger-ai-crawler-log' ), hint: __( 'Indexes for a search product that also feeds AI answers.', 'crawlledger-ai-crawler-log' ) },
	training: { label: __( 'Training', 'crawlledger-ai-crawler-log' ), hint: __( 'Collects content for model training.', 'crawlledger-ai-crawler-log' ) },
};

function Delta( { now, before, invert = false } ) {
	if ( ! before && ! now ) {
		return <span className="clg-delta clg-delta--flat">—</span>;
	}
	if ( ! before ) {
		return <span className="clg-delta clg-delta--flat">{ __( 'new', 'crawlledger-ai-crawler-log' ) }</span>;
	}
	const pct = Math.round( ( ( now - before ) / before ) * 100 );
	const good = invert ? pct <= 0 : pct >= 0;
	let tone = 'flat';
	let arrow = '±';
	if ( pct !== 0 ) {
		tone = good ? 'up' : 'down';
		arrow = pct > 0 ? '▲' : '▼';
	}
	return (
		<span className={ `clg-delta clg-delta--${ tone }` } title={ __( 'vs. the previous period', 'crawlledger-ai-crawler-log' ) }>
			{ arrow } { Math.abs( pct ) }%
		</span>
	);
}

function Stat( { label, value, sub, delta } ) {
	return (
		<div className="clg-stat">
			<span className="clg-stat-label">{ label }</span>
			<span className="clg-stat-row"><span className="clg-stat-value">{ value }</span>{ delta }</span>
			{ sub && <span className="clg-stat-sub">{ sub }</span> }
		</div>
	);
}

function Status( { code } ) {
	const thresholds = [ [ 500, 'err' ], [ 400, 'warn' ], [ 300, 'redir' ] ];
	const match = thresholds.find( ( [ min ] ) => code >= min );
	const cls = match ? match[ 1 ] : 'ok';
	return <span className={ `clg-code clg-code--${ cls }` }>{ code }</span>;
}

function EmptyState( { coverage } ) {
	return (
		<div className="clg-empty">
			<h2>{ __( 'No crawler visits recorded yet', 'crawlledger-ai-crawler-log' ) }</h2>
			<p>{ __( 'Hits are captured as they happen and written to the database every five minutes. Most sites see their first AI crawler within a day.', 'crawlledger-ai-crawler-log' ) }</p>
			{ coverage && coverage.queue.files > 0 && <p>{ sprintf(
				/* translators: %d: number of files */
				_n( '%d queue file is waiting for the next ingest.', '%d queue files are waiting for the next ingest.', coverage.queue.files, 'crawlledger-ai-crawler-log' ), coverage.queue.files ) }</p> }
			<p className="clg-muted">{ __( 'To test the pipeline, request any page with a crawler user agent, e.g. curl -A "GPTBot" and then use "Ingest queue now" under Details.', 'crawlledger-ai-crawler-log' ) }</p>
		</div>
	);
}

export default function Overview() {
	const [ range, setRange ] = useState( '7d' );
	const [ verified, setVerified ] = useState( true );
	const [ showQuiet, setShowQuiet ] = useState( false );
	const [ showAll, setShowAll ] = useState( { failing: false, top: false, recent: false } );
	const limit = ( key, list, n ) => ( showAll[ key ] ? list : list.slice( 0, n ) );
	const More = ( { k, list, n } ) => list.length > n ? <button type="button" className="clg-link clg-table-toggle" onClick={ () => setShowAll( { ...showAll, [ k ]: ! showAll[ k ] } ) }>{ showAll[ k ] ? __( 'Show fewer', 'crawlledger-ai-crawler-log' ) : sprintf(
		/* translators: %d: count */
		__( 'Show all %d', 'crawlledger-ai-crawler-log' ), list.length ) }</button> : null;
	const [ stats, setStats ] = useState( null );
	const [ urls, setUrls ] = useState( null );
	const [ coverage, setCoverage ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( '' );

	const load = () => {
		setError( '' );
		const fail = ( e ) => setError( e.message || String( e ) );
		// Each panel paints as its own data lands; the first is served from the page preload.
		api.get( '/stats', { range, verified: verified ? 1 : 0 } ).then( setStats ).catch( fail );
		api.get( '/urls', { verified: verified ? 1 : 0 } ).then( setUrls ).catch( fail );
		api.get( '/coverage' ).then( setCoverage ).catch( fail );
		api.get( '/settings' ).then( setSettings ).catch( fail );
	};
	useEffect( load, [ range, verified ] );
	// Verification off means nothing has been checked, so the "verified only" filter must not hide everything.
	useEffect( () => {
		if ( settings && ! settings.verification_enabled ) {
			setVerified( false );
		}
	}, [ settings ] );

	if ( error ) {
		return <p className="clg-error">{ error }</p>;
	}
	if ( ! stats ) {
		return <div className="clg-loading"><Spinner /></div>;
	}
	// Until the user opts in, nothing has been checked, so "verified only" would show an empty chart.
	const verifying = settings ? settings.verification_enabled : true;

	const historyDays = stats.history_days;
	const ranges = [ [ '7d', 7 ], [ '30d', 30 ], [ '90d', 90 ] ];
	const active = stats.bots.filter( ( b ) => b.total > 0 );
	const quiet = stats.bots.filter( ( b ) => b.total === 0 );
	const timing = stats.timing;
	const nothingYet = stats.totals.all === 0 && urls !== null && urls.recent.length === 0;
	const errRate = stats.totals.hits ? Math.round( ( stats.totals.errors / stats.totals.hits ) * 100 ) : 0;
	const prevErrRate = stats.previous.hits ? Math.round( ( stats.previous.errors / stats.previous.hits ) * 100 ) : 0;

	return (
		<div className="clg-overview">
			<Coverage coverage={ coverage } onChange={ load } />

			{ nothingYet ? <EmptyState coverage={ coverage } /> : (
				<>
					<div className="clg-toolbar">
						<div className="clg-segmented" role="group" aria-label={ __( 'Range', 'crawlledger-ai-crawler-log' ) }>
							{ ranges.map( ( [ key, days ] ) => (
								<button key={ key } type="button" className={ key === range ? 'is-active' : '' } disabled={ days > historyDays } title={ days > historyDays ? __( 'Longer history is part of the add-on', 'crawlledger-ai-crawler-log' ) : '' } onClick={ () => setRange( key ) }>
									{ sprintf(
										/* translators: %d: number of days */
										__( '%d days', 'crawlledger-ai-crawler-log' ), days ) }
									{ days > historyDays && <span className="clg-lock" aria-hidden="true">🔒</span> }
								</button>
							) ) }
						</div>
						{ verifying && <ToggleControl label={ __( 'Verified crawlers only', 'crawlledger-ai-crawler-log' ) } checked={ verified } onChange={ setVerified } __nextHasNoMarginBottom /> }
						<span className="clg-muted clg-toolbar-note">
							{ verifying ? (
								<>
									{ sprintf(
										/* translators: 1: verified count, 2: unverified count */
										__( '%1$s verified · %2$s unverified', 'crawlledger-ai-crawler-log' ), formatNumber( stats.totals.verified ), formatNumber( stats.totals.unverified ) ) }
									<span className="clg-help" title={ __( 'Unverified: the user agent claimed a crawler but the IP is not in the vendor\'s published ranges and reverse DNS did not confirm it — or the vendor publishes neither.', 'crawlledger-ai-crawler-log' ) }>?</span>
								</>
							) : __( 'Verification is off — visits are logged but not checked.', 'crawlledger-ai-crawler-log' ) }
						</span>
					</div>

					<div className="clg-stats">
						<Stat label={ verified ? __( 'Verified crawler visits', 'crawlledger-ai-crawler-log' ) : __( 'Crawler visits', 'crawlledger-ai-crawler-log' ) } value={ formatNumber( stats.totals.hits ) } delta={ <Delta now={ stats.totals.hits } before={ stats.previous.hits } /> } sub={ sprintf(
							/* translators: %d: number of days */
							__( 'last %d days', 'crawlledger-ai-crawler-log' ), stats.days ) } />
						<Stat label={ __( 'Distinct crawlers', 'crawlledger-ai-crawler-log' ) } value={ formatNumber( stats.totals.bots ) } delta={ <Delta now={ stats.totals.bots } before={ stats.previous.bots } /> } sub={ sprintf(
							/* translators: %d: number of crawlers known */
							__( 'of %d known', 'crawlledger-ai-crawler-log' ), stats.bots.length ) } />
						<Stat label={ __( 'Error rate', 'crawlledger-ai-crawler-log' ) } value={ `${ errRate }%` } delta={ <Delta now={ errRate } before={ prevErrRate } invert /> } sub={ sprintf(
							/* translators: %s: count */
							__( '%s responses were 4xx/5xx', 'crawlledger-ai-crawler-log' ), formatNumber( stats.totals.errors ) ) } />
						<Stat label={ __( 'Cost per visitor request', 'crawlledger-ai-crawler-log' ) } value={ timing.non_bot_avg_us === null ? '—' : `${ ( timing.non_bot_avg_us / 1000 ).toFixed( 3 ) } ms` } sub={ timing.non_bot_avg_us === null ? __( 'no samples yet', 'crawlledger-ai-crawler-log' ) : sprintf(
							/* translators: 1: budget in ms, 2: sample count */
							__( 'budget %1$s ms · 0 queries · %2$d samples', 'crawlledger-ai-crawler-log' ), ( timing.budget_us / 1000 ).toFixed( 1 ), timing.samples ) } />
					</div>

					<section className="clg-panel">
						<header className="clg-panel-head">
							<h2>{ __( 'Visits per day', 'crawlledger-ai-crawler-log' ) }</h2>
							<span className="clg-muted">{ sprintf(
								/* translators: %s: milliseconds */
								__( 'from daily aggregates · %s ms', 'crawlledger-ai-crawler-log' ), stats.query_ms ) }</span>
						</header>
						<AreaChart series={ stats.series } />
					</section>

					<section className="clg-panel">
						<header className="clg-panel-head">
							<h2>{ __( 'Crawlers', 'crawlledger-ai-crawler-log' ) }</h2>
							<span className="clg-muted">{ sprintf(
								/* translators: 1: active count, 2: quiet count */
								__( '%1$d active · %2$d not seen', 'crawlledger-ai-crawler-log' ), active.length, quiet.length ) }</span>
						</header>
						<table className="clg-table">
							<thead>
								<tr>
									<th>{ __( 'Crawler', 'crawlledger-ai-crawler-log' ) }</th>
									<th>{ __( 'Type', 'crawlledger-ai-crawler-log' ) }</th>
									<th className="clg-num">{ __( 'Visits', 'crawlledger-ai-crawler-log' ) }</th>
									<th>{ __( 'Verified', 'crawlledger-ai-crawler-log' ) }</th>
									<th className="clg-num">{ __( 'Errors', 'crawlledger-ai-crawler-log' ) }</th>
									<th>{ __( 'Last seen', 'crawlledger-ai-crawler-log' ) }</th>
									<th>{ __( 'Trend', 'crawlledger-ai-crawler-log' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ ( showQuiet ? stats.bots : active ).map( ( b ) => (
									<tr key={ b.id } className={ b.total === 0 ? 'clg-row-quiet' : '' }>
										<td><strong>{ b.name }</strong><span className="clg-muted"> { b.vendor }</span></td>
										<td><span className={ `clg-badge clg-badge--${ b.type }` } title={ TYPE[ b.type ].hint }>{ TYPE[ b.type ].label }</span></td>
										<td className="clg-num">{ formatNumber( b.total ) }</td>
										<td>
											{ b.verify === 'none'
												? <span className="clg-muted" title={ __( 'This vendor publishes no IP ranges or reverse-DNS pattern, so its hits cannot be verified.', 'crawlledger-ai-crawler-log' ) }>{ __( 'not verifiable', 'crawlledger-ai-crawler-log' ) }</span>
												: <Proportion value={ b.verified } total={ b.total } title={ sprintf(
													/* translators: 1: verified, 2: unverified */
													__( '%1$s verified, %2$s unverified', 'crawlledger-ai-crawler-log' ), formatNumber( b.verified ), formatNumber( b.unverified ) ) } /> }
										</td>
										<td className="clg-num">{ b.errors > 0 ? <span className="clg-err">{ formatNumber( b.errors ) }</span> : '0' }</td>
										<td>{ b.last_seen ? timeAgo( b.last_seen ) : <span className="clg-muted">{ __( 'never', 'crawlledger-ai-crawler-log' ) }</span> }</td>
										<td><Sparkline values={ b.spark } /></td>
									</tr>
								) ) }
							</tbody>
						</table>
						{ quiet.length > 0 && (
							<button type="button" className="clg-link clg-table-toggle" onClick={ () => setShowQuiet( ! showQuiet ) }>
								{ showQuiet ? __( 'Hide crawlers with no visits', 'crawlledger-ai-crawler-log' ) : sprintf(
									/* translators: %d: count */
									_n( 'Show %d crawler with no visits', 'Show %d crawlers with no visits', quiet.length, 'crawlledger-ai-crawler-log' ), quiet.length ) }
							</button>
						) }
					</section>

					<div className="clg-grid-2">
						<section className="clg-panel">
							<header className="clg-panel-head"><h2>{ __( 'Failing for crawlers', 'crawlledger-ai-crawler-log' ) }</h2><span className="clg-muted">{ __( '7 days', 'crawlledger-ai-crawler-log' ) }</span></header>
							{ urls && urls.failing.length === 0 && <p className="clg-quiet-msg">{ __( 'No 4xx or 5xx responses to crawlers. Good.', 'crawlledger-ai-crawler-log' ) }</p> }
							{ urls && urls.failing.length > 0 && (
								<table className="clg-table clg-table--compact">
									<thead><tr><th>{ __( 'URL', 'crawlledger-ai-crawler-log' ) }</th><th>{ __( 'Status', 'crawlledger-ai-crawler-log' ) }</th><th className="clg-num">{ __( 'Hits', 'crawlledger-ai-crawler-log' ) }</th><th className="clg-num">{ __( 'Crawlers', 'crawlledger-ai-crawler-log' ) }</th></tr></thead>
									<tbody>{ limit( 'failing', urls.failing, 10 ).map( ( u ) => <tr key={ u.url }><td className="clg-url">{ u.url }</td><td><Status code={ u.status } /></td><td className="clg-num">{ u.hits }</td><td className="clg-num">{ u.bots }</td></tr> ) }</tbody>
								</table>
							) }
							{ urls && <More k="failing" list={ urls.failing } n={ 10 } /> }
							{ coverage && coverage.page_cache && coverage.page_cache.server_level && (
								<p className="clg-muted">{ __( 'Failures from an allowed crawler may come from a CDN or server-level bot control above WordPress (e.g. Cloudflare\'s AI-scraper toggle), which this plugin cannot override.', 'crawlledger-ai-crawler-log' ) }</p>
							) }
						</section>
						<section className="clg-panel">
							<header className="clg-panel-head"><h2>{ __( 'Most crawled', 'crawlledger-ai-crawler-log' ) }</h2><span className="clg-muted">{ __( '7 days', 'crawlledger-ai-crawler-log' ) }</span></header>
							{ urls && urls.top.length === 0 && <p className="clg-quiet-msg">{ __( 'Nothing recorded in the last 7 days.', 'crawlledger-ai-crawler-log' ) }</p> }
							{ urls && urls.top.length > 0 && (
								<table className="clg-table clg-table--compact">
									<thead><tr><th>{ __( 'URL', 'crawlledger-ai-crawler-log' ) }</th><th className="clg-num">{ __( 'Hits', 'crawlledger-ai-crawler-log' ) }</th><th className="clg-num">{ __( 'Crawlers', 'crawlledger-ai-crawler-log' ) }</th></tr></thead>
									<tbody>{ limit( 'top', urls.top, 10 ).map( ( u ) => <tr key={ u.url }><td className="clg-url">{ u.url }</td><td className="clg-num">{ u.hits }</td><td className="clg-num">{ u.bots }</td></tr> ) }</tbody>
								</table>
							) }
							{ urls && <More k="top" list={ urls.top } n={ 10 } /> }
						</section>
					</div>

					<section className="clg-panel">
						<header className="clg-panel-head"><h2>{ __( 'Latest visits', 'crawlledger-ai-crawler-log' ) }</h2><span className="clg-muted">{ __( 'most recent 50 · all crawlers', 'crawlledger-ai-crawler-log' ) }</span></header>
						{ urls && urls.recent.length === 0 && <p className="clg-quiet-msg">{ __( 'Nothing in the raw log yet.', 'crawlledger-ai-crawler-log' ) }</p> }
						{ urls && urls.recent.length > 0 && (
							<table className="clg-table clg-table--compact clg-feed">
								<thead><tr><th>{ __( 'When', 'crawlledger-ai-crawler-log' ) }</th><th>{ __( 'Crawler', 'crawlledger-ai-crawler-log' ) }</th><th>{ __( 'URL', 'crawlledger-ai-crawler-log' ) }</th><th>{ __( 'Status', 'crawlledger-ai-crawler-log' ) }</th><th>{ __( 'Verified', 'crawlledger-ai-crawler-log' ) }</th></tr></thead>
								<tbody>
									{ limit( 'recent', urls.recent, 20 ).map( ( r, i ) => {
										const bot = stats.bots.find( ( b ) => b.id === r.bot_id );
										return (
											<tr key={ i }>
												<td className="clg-nowrap" title={ `${ r.hit_at } UTC` }>{ timeAgo( r.hit_at ) }</td>
												<td>{ bot ? bot.name : `#${ r.bot_id }` }</td>
												<td className="clg-url">{ r.url }</td>
												<td><Status code={ r.status } />{ r.is_cached && <span className="clg-muted" title={ __( 'Served from a page cache', 'crawlledger-ai-crawler-log' ) }> ⚡</span> }</td>
												<td>{ r.verified ? <span className="clg-ok">✓</span> : <span className="clg-muted">—</span> }</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						) }
						{ urls && <More k="recent" list={ urls.recent } n={ 20 } /> }
					</section>

					<p className="clg-footnote">{ sprintf(
						/* translators: 1: row count, 2: size, 3: aggregate row count */
						__( 'Storage: %1$s raw rows (%2$s), %3$s daily aggregate rows kept indefinitely.', 'crawlledger-ai-crawler-log' ), formatNumber( stats.sizes.hits.rows ), formatBytes( stats.sizes.hits.bytes ), formatNumber( stats.sizes.daily.rows ) ) }</p>
				</>
			) }
		</div>
	);
}
