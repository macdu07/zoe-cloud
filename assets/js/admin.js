( function () {
	'use strict';

	if ( ! window.zoecloudAdmin ) {
		return;
	}

	const config = window.zoecloudAdmin;
	const t = ( key, fallback = '' ) => config.i18n?.[ key ] || fallback;
	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );
	const state = { status: null, backups: [], activity: [], selected: new Set(), restoreFilename: '', polling: null, pollFailures: 0 };

	const escapeHtml = ( value ) => String( value ?? '' ).replace( /[&<>"']/g, ( char ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' } )[ char ] );
	const formatBytes = ( bytes ) => {
		const value = Number( bytes ) || 0;
		if ( value < 1024 ) return `${ value } B`;
		const units = [ 'KB', 'MB', 'GB', 'TB' ];
		let amount = value / 1024;
		let index = 0;
		while ( amount >= 1024 && index < units.length - 1 ) { amount /= 1024; index++; }
		return `${ amount.toLocaleString( undefined, { maximumFractionDigits: amount >= 10 ? 0 : 1 } ) } ${ units[ index ] }`;
	};
	const formatDate = ( value, relative = false ) => {
		if ( ! value ) return '—';
		const normalized = /Z$|[+-]\d\d:\d\d$/.test( value ) ? value : `${ value.replace( ' ', 'T' ) }Z`;
		const date = new Date( normalized );
		if ( Number.isNaN( date.getTime() ) ) return value;
		if ( relative ) {
			const minutes = Math.round( ( date.getTime() - Date.now() ) / 60000 );
			if ( Math.abs( minutes ) < 60 ) return new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ).format( minutes, 'minute' );
			const hours = Math.round( minutes / 60 );
			if ( Math.abs( hours ) < 48 ) return new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ).format( hours, 'hour' );
		}
		return new Intl.DateTimeFormat( undefined, { dateStyle: 'medium', timeStyle: 'short' } ).format( date );
	};
	const buildUrl = ( path ) => {
		const [ endpoint, query ] = path.split( '?' );
		const url = new URL( config.root + endpoint );
		if ( query ) new URLSearchParams( query ).forEach( ( value, key ) => url.searchParams.append( key, value ) );
		return url.toString();
	};
	const request = async ( path, options = {} ) => {
		const response = await fetch( buildUrl( path ), {
			method: options.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, ...( options.headers || {} ) },
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} );
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			const error = new Error( data.message || t( 'unknownError', 'Request failed.' ) );
			error.status = response.status;
			throw error;
		}
		return data;
	};
	const feedback = ( selector, message, error = false ) => {
		const node = $( selector );
		if ( ! node ) return;
		node.textContent = message;
		node.classList.toggle( 'is-error', error );
		node.classList.toggle( 'is-success', ! error && !! message );
	};

	const activateTab = ( name, updateHash = true ) => {
		const active = $( `[data-zoecloud-tab="${ name }"]` );
		if ( ! active ) return;
		$$( '[data-zoecloud-tab]' ).forEach( ( tab ) => {
			const selected = tab === active;
			tab.classList.toggle( 'is-active', selected );
			tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			tab.tabIndex = selected ? 0 : -1;
		} );
		$$( '[data-zoecloud-panel]' ).forEach( ( panel ) => {
			const selected = panel.dataset.zoecloudPanel === name;
			panel.classList.toggle( 'is-active', selected );
			panel.hidden = ! selected;
		} );
		if ( updateHash ) history.replaceState( null, '', `#${ name }` );
	};
	$$( '[data-zoecloud-tab]' ).forEach( ( tab, index, tabs ) => {
		tab.addEventListener( 'click', () => activateTab( tab.dataset.zoecloudTab ) );
		tab.addEventListener( 'keydown', ( event ) => {
			if ( ! [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].includes( event.key ) ) return;
			event.preventDefault();
			let next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : ( index + ( event.key === 'ArrowRight' ? 1 : -1 ) + tabs.length ) % tabs.length;
			tabs[ next ].focus(); activateTab( tabs[ next ].dataset.zoecloudTab );
		} );
	} );
	$$( '[data-zoecloud-go]' ).forEach( ( button ) => button.addEventListener( 'click', () => activateTab( button.dataset.zoecloudGo ) ) );
	if ( [ 'overview', 'backups', 'automation', 'storage', 'activity' ].includes( location.hash.slice( 1 ) ) ) activateTab( location.hash.slice( 1 ), false );

	const renderPreflight = ( checks ) => {
		const rows = [
			[ t( 'zipArchive', 'ZipArchive' ), checks.ziparchive ], [ t( 'uploadsWritable', 'Uploads writable' ), checks.uploads_writable ], [ t( 'backupWritable', 'Backup files writable' ), checks.can_create_files ],
			[ t( 'freeDisk', 'Free disk' ), checks.disk_free_bytes === null ? '—' : formatBytes( checks.disk_free_bytes ) ], [ t( 'memory', 'Memory' ), checks.memory_limit || '—' ],
			[ t( 'executionTime', 'Execution time' ), checks.max_execution_time || '—' ], [ t( 'cron', 'WP-Cron' ), checks.wp_cron_disabled ? t( 'disabled', 'Disabled' ) : t( 'available', 'Available' ) ],
		];
		const list = $( '#zoecloud-preflight' );
		if ( list ) list.innerHTML = rows.map( ( [ label, value ] ) => `<li class="${ value === false || value === t( 'disabled', 'Disabled' ) ? 'is-error' : '' }"><span>${ escapeHtml( label ) }</span><strong>${ escapeHtml( value === true ? 'OK' : value === false ? t( 'missing', 'Missing' ) : value ) }</strong></li>` ).join( '' );
		$( '#zoecloud-health-summary' ).textContent = checks.ready ? t( 'healthReady', 'Ready to create backups.' ) : t( 'healthBlocked', 'Action is required before backups can run.' );
	};
	const renderSummary = ( data ) => {
		const summary = data.summary || {};
		const latest = summary.latest_backup;
		$( '#zoecloud-summary-latest' ).textContent = latest ? formatDate( latest.created_at, true ) : t( 'never', 'Never' );
		$( '#zoecloud-summary-latest-detail' ).textContent = latest ? `${ formatBytes( latest.size ) } · ${ latest.scope === 'full' ? t( 'fullSite', 'Full site' ) : t( 'siteData', 'Site data' ) }` : t( 'createFirst', 'Create your first recovery point.' );
		$( '#zoecloud-summary-next' ).textContent = data.schedule?.enabled && data.schedule.next_run ? formatDate( data.schedule.next_run, true ) : t( 'notScheduled', 'Not scheduled' );
		$( '#zoecloud-summary-timezone' ).textContent = data.schedule?.enabled ? `${ formatDate( data.schedule.next_run ) } · ${ data.schedule.timezone }` : t( 'enableAutomation', 'Enable automation to stay protected.' );
		$( '#zoecloud-summary-storage' ).textContent = data.cloud?.configured ? data.cloud.label : t( 'localOnly', 'Local only' );
		$( '#zoecloud-summary-storage-detail' ).textContent = data.cloud?.configured ? data.cloud.bucket : t( 'connectStorage', 'Connect off-site storage.' );
		$( '#zoecloud-summary-health' ).textContent = data.health?.ready ? t( 'ready', 'Ready' ) : t( 'attention', 'Needs attention' );
		$( '#zoecloud-summary-health-detail' ).textContent = data.health?.cron_available ? t( 'cronAvailable', 'Background tasks available' ) : t( 'cronDisabled', 'WP-Cron is disabled' );
		$( '#zoecloud-metric-count' ).textContent = summary.backup_count || 0;
		$( '#zoecloud-metric-size' ).textContent = formatBytes( summary.local_total_bytes );
		$( '#zoecloud-metric-activity' ).textContent = summary.latest_job ? formatDate( summary.latest_job.updated_at, true ) : '—';
		if ( $( '#zoecloud-onboarding' ) ) $( '#zoecloud-onboarding' ).hidden = Number( summary.backup_count || 0 ) > 0;
		const protectedState = latest && data.health?.ready;
		const global = $( '#zoecloud-global-status' );
		global.textContent = protectedState ? t( 'protected', 'Protected' ) : t( 'setupNeeded', 'Setup needed' );
		global.classList.toggle( 'is-ready', !! protectedState );
		$( '#zoecloud-cloud-hint' ).textContent = data.cloud?.configured ? `${ data.cloud.label } · ${ data.cloud.bucket }` : t( 'localBackupHint', 'Cloud storage is not configured; this backup will remain local.' );
		const cloudToggle = $( '#zoecloud-upload-cloud' );
		cloudToggle.disabled = ! data.cloud?.configured;
		cloudToggle.checked = !! data.cloud?.configured;
		renderPreflight( data.preflight || {} );
	};

	const statusBadge = ( value, label ) => `<span class="zoecloud-badge is-${ escapeHtml( value ) }">${ escapeHtml( label ) }</span>`;
	const filteredBackups = () => {
		const query = ( $( '#zoecloud-backup-search' )?.value || '' ).toLowerCase();
		const filter = $( '#zoecloud-backup-filter' )?.value || 'all';
		return state.backups.filter( ( backup ) => {
			const matchesSearch = `${ backup.filename } ${ backup.manifest?.domain || '' }`.toLowerCase().includes( query );
			const matchesFilter = filter === 'all' || backup.source === filter || ( filter === 'locked' && backup.locked );
			return matchesSearch && matchesFilter;
		} );
	};
	const renderBackups = () => {
		const table = $( '#zoecloud-backups-table' );
		const backups = filteredBackups();
		if ( ! backups.length ) { table.innerHTML = `<tr><td colspan="6" class="zoecloud-empty">${ escapeHtml( t( 'noBackups', 'No backups found.' ) ) }</td></tr>`; return; }
		table.innerHTML = backups.map( ( backup ) => {
			const id = backup.id;
			const downloadUrl = buildUrl( `backups/${ encodeURIComponent( id ) }?_wpnonce=${ encodeURIComponent( config.nonce ) }` );
			const integrity = backup.checksum ? statusBadge( 'verified', t( 'verified', 'Verified' ) ) : statusBadge( 'unknown', t( 'notVerified', 'Not verified' ) );
			const location = backup.cloud ? `${ statusBadge( 'cloud', backup.cloud.provider?.toUpperCase() || 'Cloud' ) } ${ statusBadge( 'local', t( 'local', 'Local' ) ) }` : statusBadge( backup.cloud_error ? 'failed' : 'local', backup.cloud_error ? t( 'uploadFailed', 'Upload failed' ) : t( 'local', 'Local' ) );
			return `<tr data-id="${ escapeHtml( id ) }"><th class="check-column"><input type="checkbox" class="zoecloud-row-select" value="${ escapeHtml( id ) }" ${ state.selected.has( id ) ? 'checked' : '' } aria-label="${ escapeHtml( backup.filename ) }"></th><td><div class="zoecloud-backup-name"><strong>${ escapeHtml( backup.manifest?.domain || backup.filename ) }</strong>${ backup.locked ? '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' : '' }<small>${ escapeHtml( formatDate( backup.created_at ) ) } · ${ escapeHtml( formatBytes( backup.size ) ) } · ${ escapeHtml( backup.scope === 'full' ? t( 'fullSite', 'Full site' ) : t( 'siteData', 'Site data' ) ) }</small></div></td><td>${ statusBadge( backup.source || 'manual', t( backup.source || 'manual', backup.source || 'manual' ) ) }</td><td>${ location }</td><td>${ integrity }</td><td><div class="zoecloud-row-actions"><a class="button" href="${ escapeHtml( downloadUrl ) }">${ escapeHtml( t( 'download', 'Download' ) ) }</a><button type="button" class="button zoecloud-restore-backup">${ escapeHtml( t( 'restore', 'Restore' ) ) }</button><button type="button" class="button zoecloud-lock-backup" data-id="${ escapeHtml( id ) }" data-locked="${ backup.locked ? '1' : '0' }">${ escapeHtml( backup.locked ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ) ) }</button><button type="button" class="button-link-delete zoecloud-delete-backup" data-id="${ escapeHtml( id ) }" ${ backup.locked ? 'disabled' : '' }>${ escapeHtml( t( 'delete', 'Delete' ) ) }</button></div></td></tr>`;
		} ).join( '' );
		$( '#zoecloud-bulk-delete' ).disabled = state.selected.size === 0;
	};

	const renderJob = ( job ) => {
		const node = $( '#zoecloud-job-status' );
		if ( ! job || ! node ) return;
		node.innerHTML = `<div class="zoecloud-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${ Number( job.progress ) || 0 }"><span style="width:${ Math.min( 100, Math.max( 0, Number( job.progress ) || 0 ) ) }%"></span></div><div><strong>${ escapeHtml( job.message || t( 'loading', 'Working…' ) ) }</strong><span>${ Number( job.progress ) || 0 }%</span></div>`;
	};
	const pollJob = async ( id, isRestore = false ) => {
		window.clearTimeout( state.polling );
		try {
			let job = await request( `jobs/${ encodeURIComponent( id ) }` );
			state.pollFailures = 0;
			renderJob( job );
			if ( [ 'completed', 'failed' ].includes( job.status ) ) {
				const failed = job.status === 'failed';
				const message = isRestore && ! failed ? t( 'restoreCompleted', 'Restore completed successfully.' ) : job.message;
				feedback( '#zoecloud-feedback', message, failed );
				try {
					await refresh();
				} catch ( refreshError ) {
					// The restored site may briefly interrupt the health request. The
					// terminal job state is already known, so do not hide its result.
				}
				if ( isRestore && ! failed ) {
					if ( window.confirm( t( 'restoreReloadConfirm', 'Restore completed successfully. Reload the page now?' ) ) ) {
						window.location.reload();
					} else {
						feedback( '#zoecloud-feedback', t( 'restoreCompletedNoReload', 'Restore completed successfully. The page was not reloaded.' ) );
					}
				}
				return;
			}
			state.polling = window.setTimeout( () => pollJob( id, isRestore ), 2000 );
		} catch ( error ) {
			// WordPress may briefly return 503 while the final table exchange enables
			// maintenance mode. Keep polling so the UI recovers automatically.
			if ( error.status >= 500 || ! error.status ) {
				state.pollFailures += 1;
				feedback( isRestore ? '#zoecloud-restore-feedback' : '#zoecloud-feedback', t( 'reconnecting', 'The job is still running. Reconnecting…' ) );
				state.polling = window.setTimeout( () => pollJob( id, isRestore ), Math.min( 10000, 2000 + ( state.pollFailures * 500 ) ) );
				return;
			}
			feedback( isRestore ? '#zoecloud-restore-feedback' : '#zoecloud-feedback', error.message, true );
		}
	};

	const renderActivity = () => {
		const filter = $( '#zoecloud-activity-filter' )?.value || 'all';
		const jobs = state.activity.filter( ( job ) => filter === 'all' || job.type === filter || ( filter === 'failed' && job.status === 'failed' ) );
		const table = $( '#zoecloud-activity-table' );
		if ( ! jobs.length ) { table.innerHTML = `<tr><td colspan="5" class="zoecloud-empty">${ escapeHtml( t( 'noActivity', 'No activity yet.' ) ) }</td></tr>`; return; }
		table.innerHTML = jobs.map( ( job ) => `<tr><td><strong>${ escapeHtml( t( job.type, job.type ) ) }</strong><small class="zoecloud-id">${ escapeHtml( job.id ) }</small></td><td>${ escapeHtml( formatDate( job.created_at ) ) }</td><td>${ statusBadge( job.status, t( job.status, job.status ) ) }</td><td><strong>${ escapeHtml( job.message || '' ) }</strong><small>${ escapeHtml( job.events?.length ? `${ job.events.length } ${ t( 'events', 'events' ) }` : '' ) }</small></td><td><a class="button" href="${ escapeHtml( buildUrl( `activity/${ encodeURIComponent( job.id ) }/download?_wpnonce=${ encodeURIComponent( config.nonce ) }` ) ) }">${ escapeHtml( t( 'downloadLog', 'Download log' ) ) }</a></td></tr>` ).join( '' );
	};
	const renderCloudBackups = ( objects ) => {
		const table = $( '#zoecloud-cloud-backups' ); if ( ! table ) return;
		if ( ! objects.length ) { table.innerHTML = `<tr><td colspan="4" class="zoecloud-empty">${ escapeHtml( t( 'noBackups', 'No cloud backups found.' ) ) }</td></tr>`; return; }
		table.innerHTML = objects.map( ( object ) => `<tr><td><strong>${ escapeHtml( object.filename ) }</strong><small class="zoecloud-id">${ escapeHtml( object.key ) }</small></td><td>${ escapeHtml( formatDate( object.last_modified ) ) }</td><td>${ escapeHtml( formatBytes( object.size ) ) }</td><td><button type="button" class="button zoecloud-cloud-download" data-id="${ escapeHtml( object.id ) }">${ escapeHtml( t( 'downloadVerify', 'Download & verify' ) ) }</button></td></tr>` ).join( '' );
	};

	const refresh = async () => {
		const data = await request( 'health' );
		state.status = data; state.backups = data.backups || []; state.activity = data.jobs || [];
		renderSummary( data ); renderBackups(); renderActivity();
	};

	$( '#zoecloud-create-backup' )?.addEventListener( 'click', async ( event ) => {
		const button = event.currentTarget; button.disabled = true; feedback( '#zoecloud-feedback', t( 'loading', 'Queueing…' ) );
		try {
			const scope = $( 'input[name="zoecloud_scope"]:checked' )?.value || 'site_data';
			const job = await request( 'backups', { method: 'POST', body: { include_core: scope === 'full', upload_cloud: !! $( '#zoecloud-upload-cloud' )?.checked } } );
			feedback( '#zoecloud-feedback', t( 'backupQueued', 'Backup queued.' ) ); renderJob( job ); pollJob( job.id );
		} catch ( error ) { feedback( '#zoecloud-feedback', error.message, true ); } finally { button.disabled = false; }
	} );
	[ '#zoecloud-backup-search', '#zoecloud-backup-filter' ].forEach( ( selector ) => $( selector )?.addEventListener( 'input', renderBackups ) );
	$( '#zoecloud-select-all' )?.addEventListener( 'change', ( event ) => { filteredBackups().forEach( ( backup ) => event.target.checked ? state.selected.add( backup.id ) : state.selected.delete( backup.id ) ); renderBackups(); } );
	$( '#zoecloud-backups-table' )?.addEventListener( 'change', ( event ) => { if ( ! event.target.matches( '.zoecloud-row-select' ) ) return; event.target.checked ? state.selected.add( event.target.value ) : state.selected.delete( event.target.value ); $( '#zoecloud-bulk-delete' ).disabled = state.selected.size === 0; } );
	$( '#zoecloud-backups-table' )?.addEventListener( 'click', async ( event ) => {
		const lock = event.target.closest( '.zoecloud-lock-backup' ); const remove = event.target.closest( '.zoecloud-delete-backup' ); const restore = event.target.closest( '.zoecloud-restore-backup' );
		try {
			if ( lock ) { await request( `backups/${ encodeURIComponent( lock.dataset.id ) }`, { method: 'PATCH', body: { locked: lock.dataset.locked !== '1' } } ); await refresh(); }
			if ( remove ) { state.selected = new Set( [ remove.dataset.id ] ); await deleteSelected(); }
			if ( restore ) openRestore( restore.closest( 'tr' ).dataset.id );
		} catch ( error ) { feedback( '#zoecloud-feedback', error.message, true ); }
	} );
	const deleteSelected = async () => {
		if ( ! state.selected.size || ! window.confirm( t( 'deleteConfirm', 'Delete selected backups?' ) ) ) return;
		const result = await request( 'backups/bulk-delete', { method: 'POST', body: { ids: Array.from( state.selected ) } } );
		state.selected.clear(); feedback( '#zoecloud-feedback', result.errors && Object.keys( result.errors ).length ? Object.values( result.errors ).join( ' ' ) : t( 'deleted', 'Backups deleted.' ), !! ( result.errors && Object.keys( result.errors ).length ) ); await refresh();
	};
	$( '#zoecloud-bulk-delete' )?.addEventListener( 'click', () => deleteSelected().catch( ( error ) => feedback( '#zoecloud-feedback', error.message, true ) ) );

	const dialog = $( '#zoecloud-restore-dialog' );
	const hostnameInput = $( '#zoecloud-restore-hostname' );
	const restoreUrlGrid = dialog?.querySelector( '.zoecloud-form-grid' );
	if ( restoreUrlGrid && ! dialog.querySelector( '.zoecloud-url-hint' ) ) {
		const restoreUrlHint = document.createElement( 'p' );
		restoreUrlHint.className = 'zoecloud-url-hint';
		restoreUrlHint.textContent = t( 'restoreUrlHint', 'Leave both URLs unchanged for a same-site restore. Change them only when moving to a different domain, protocol, or directory.' );
		restoreUrlGrid.insertAdjacentElement( 'afterend', restoreUrlHint );
	}
	const copyHostname = document.createElement( 'button' );
	if ( hostnameInput ) {
		copyHostname.type = 'button';
		copyHostname.className = 'zoecloud-copy-hostname';
		copyHostname.setAttribute( 'aria-label', t( 'copyHostname', 'Copy hostname' ) );
		copyHostname.title = t( 'copyHostname', 'Copy hostname' );
		copyHostname.innerHTML = `<span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="screen-reader-text">${ escapeHtml( t( 'copyHostname', 'Copy hostname' ) ) }</span>`;
		const hostnameField = hostnameInput.closest( '.zoecloud-field' );
		const hostnameValue = hostnameField?.querySelector( 'strong' );
		if ( hostnameValue ) {
			const hostnameRow = document.createElement( 'span' );
			hostnameRow.className = 'zoecloud-hostname-value';
			hostnameValue.parentNode.insertBefore( hostnameRow, hostnameValue );
			hostnameRow.append( hostnameValue, copyHostname );
		} else {
			hostnameField?.append( copyHostname );
		}
		copyHostname.addEventListener( 'click', async () => {
			try {
				if ( navigator.clipboard?.writeText ) {
					await navigator.clipboard.writeText( config.hostname );
				} else {
					hostnameInput.focus(); hostnameInput.select();
					if ( ! document.execCommand( 'copy' ) ) throw new Error( 'copy_failed' );
				}
				copyHostname.classList.add( 'is-copied' );
				feedback( '#zoecloud-restore-feedback', t( 'hostnameCopied', 'Hostname copied.' ) );
				window.setTimeout( () => copyHostname.classList.remove( 'is-copied' ), 1400 );
			} catch ( error ) {
				feedback( '#zoecloud-restore-feedback', t( 'copyFailed', 'Could not copy the hostname.' ), true );
			}
		} );
	}
	const openRestore = async ( backupId ) => {
		state.restoreBackupId = backupId; $( '#zoecloud-restore-hostname' ).value = ''; $( '#zoecloud-run-restore' ).disabled = true; dialog.showModal();
		$( '#zoecloud-restore-plan' ).innerHTML = `<p>${ escapeHtml( t( 'loading', 'Inspecting backup…' ) ) }</p>`;
		try {
			const backup = await request( `backups/${ encodeURIComponent( backupId ) }/verify`, { method: 'POST' } ); const plan = backup.manifest || {};
			$( '#zoecloud-restore-search' ).value = plan.home_url || state.status?.summary?.latest_backup?.manifest?.home_url || '';
			$( '#zoecloud-restore-plan' ).innerHTML = `<div><span>${ escapeHtml( t( 'origin', 'Origin' ) ) }</span><strong>${ escapeHtml( plan.home_url || '—' ) }</strong></div><div><span>${ escapeHtml( t( 'files', 'Files' ) ) }</span><strong>${ Number( plan.files_count ) || 0 }</strong></div><div><span>${ escapeHtml( t( 'databaseRows', 'Database rows' ) ) }</span><strong>${ Number( plan.database_rows ) || 0 }</strong></div><div><span>${ escapeHtml( t( 'archiveSize', 'Archive size' ) ) }</span><strong>${ escapeHtml( formatBytes( backup.size ) ) }</strong></div>`;
			feedback( '#zoecloud-restore-feedback', t( 'validBackup', 'Backup verified.' ) );
		} catch ( error ) { feedback( '#zoecloud-restore-feedback', error.message, true ); }
	};
	$( '#zoecloud-restore-hostname' )?.addEventListener( 'input', ( event ) => { $( '#zoecloud-run-restore' ).disabled = event.target.value.trim().toLowerCase() !== config.hostname.toLowerCase(); } );
	$( '#zoecloud-cancel-restore' )?.addEventListener( 'click', () => dialog.close() );
	$( '#zoecloud-run-restore' )?.addEventListener( 'click', async ( event ) => {
		const button = event.currentTarget; button.disabled = true; feedback( '#zoecloud-restore-feedback', t( 'loading', 'Queueing protected restore…' ) );
		try {
			const job = await request( 'restores', { method: 'POST', body: { backup_id: state.restoreBackupId, search: $( '#zoecloud-restore-search' ).value, replace: $( '#zoecloud-restore-replace' ).value, hostname: $( '#zoecloud-restore-hostname' ).value } } );
			dialog.close();
			feedback( '#zoecloud-feedback', t( 'restoreQueued', 'Protected restore queued.' ) );
			renderJob( job ); pollJob( job.id, true );
		} catch ( error ) { feedback( '#zoecloud-restore-feedback', error.message, true ); button.disabled = false; }
	} );

	const uploadInput = $( '#zoecloud-upload-file' ); const uploadArea = $( '#zoecloud-upload-area' );
	const setUploadFile = ( file ) => { if ( ! file ) return; const dt = new DataTransfer(); dt.items.add( file ); uploadInput.files = dt.files; $( '#zoecloud-upload-filename' ).textContent = file.name; };
	uploadInput?.addEventListener( 'change', () => { if ( uploadInput.files?.[0] ) $( '#zoecloud-upload-filename' ).textContent = uploadInput.files[0].name; } );
	uploadArea?.addEventListener( 'dragover', ( event ) => { event.preventDefault(); uploadArea.classList.add( 'is-dragover' ); } );
	uploadArea?.addEventListener( 'dragleave', () => uploadArea.classList.remove( 'is-dragover' ) );
	uploadArea?.addEventListener( 'drop', ( event ) => { event.preventDefault(); uploadArea.classList.remove( 'is-dragover' ); setUploadFile( event.dataTransfer?.files?.[0] ); } );
	$( '#zoecloud-upload-zip' )?.addEventListener( 'click', async ( event ) => {
		const file = uploadInput?.files?.[0]; if ( ! file ) { feedback( '#zoecloud-upload-feedback', t( 'selectZip', 'Choose a ZIP first.' ), true ); return; }
		const button = event.currentTarget; button.disabled = true; const data = new FormData(); data.append( 'zip_file', file ); feedback( '#zoecloud-upload-feedback', t( 'loading', 'Uploading…' ) );
		try {
			const response = await fetch( buildUrl( 'backups' ), { method: 'POST', headers: { 'X-WP-Nonce': config.nonce }, body: data } ); const result = await response.json().catch( () => ( {} ) ); if ( ! response.ok ) throw new Error( result.message || t( 'unknownError' ) );
			uploadInput.value = ''; $( '#zoecloud-upload-filename' ).textContent = t( 'chooseZip', 'Choose a ZIP or drop it here' ); feedback( '#zoecloud-upload-feedback', t( 'uploadComplete', 'Backup imported.' ) ); await refresh();
		} catch ( error ) { feedback( '#zoecloud-upload-feedback', error.message, true ); } finally { button.disabled = false; }
	} );

	const provider = $( '#zoecloud_storage_provider' );
	const updateProvider = () => $$( '[data-zoecloud-provider-fields]' ).forEach( ( fields ) => { fields.hidden = fields.dataset.zoecloudProviderFields !== provider?.value; } );
	provider?.addEventListener( 'change', updateProvider ); updateProvider();
	const schedule = $( '#zoecloud_schedule' ); const updateSchedule = () => { if ( $( '#zoecloud_schedule_weekday_row' ) ) $( '#zoecloud_schedule_weekday_row' ).hidden = schedule?.value !== 'weekly'; }; schedule?.addEventListener( 'change', updateSchedule ); updateSchedule();
	$( '#zoecloud-test-storage' )?.addEventListener( 'click', async ( event ) => { const button = event.currentTarget; button.disabled = true; feedback( '#zoecloud-storage-feedback', t( 'loading', 'Testing…' ) ); try { await request( 'storage/test', { method: 'POST' } ); feedback( '#zoecloud-storage-feedback', t( 'connectionSuccess', 'Connection successful.' ) ); } catch ( error ) { feedback( '#zoecloud-storage-feedback', error.message, true ); } finally { button.disabled = false; } } );
	$( '#zoecloud-load-cloud' )?.addEventListener( 'click', async ( event ) => { const button = event.currentTarget; button.disabled = true; feedback( '#zoecloud-cloud-feedback', t( 'loading', 'Loading…' ) ); try { const page = await request( 'cloud/backups' ); renderCloudBackups( page.objects || [] ); feedback( '#zoecloud-cloud-feedback', '' ); } catch ( error ) { feedback( '#zoecloud-cloud-feedback', error.message, true ); } finally { button.disabled = false; } } );
	$( '#zoecloud-cloud-backups' )?.addEventListener( 'click', async ( event ) => { const button = event.target.closest( '.zoecloud-cloud-download' ); if ( ! button ) return; button.disabled = true; try { const job = await request( `cloud/backups/${ encodeURIComponent( button.dataset.id ) }/download`, { method: 'POST' } ); feedback( '#zoecloud-cloud-feedback', t( 'backupQueued', 'Download queued.' ) ); pollJob( job.id ); } catch ( error ) { feedback( '#zoecloud-cloud-feedback', error.message, true ); button.disabled = false; } } );
	$( '#zoecloud-activity-filter' )?.addEventListener( 'change', renderActivity );

	refresh().catch( ( error ) => { $( '#zoecloud-global-status' ).textContent = t( 'attention', 'Needs attention' ); feedback( '#zoecloud-feedback', error.message, true ); } );
}() );
