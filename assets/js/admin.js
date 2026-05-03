( function () {
	const state = {
		table: document.getElementById( 'zoecloud-backups-table' ),
		statusText: document.getElementById( 'zoecloud-status-text' ),
		feedback: document.getElementById( 'zoecloud-feedback' ),
		preflight: document.getElementById( 'zoecloud-preflight' ),
		createButton: document.getElementById( 'zoecloud-create-backup' ),
		includeCore: document.getElementById( 'zoecloud-include-core' ),
		uploadDrive: document.getElementById( 'zoecloud-upload-drive' ),
		restoreFilename: document.getElementById( 'zoecloud-restore-filename' ),
		restoreSearch: document.getElementById( 'zoecloud-restore-search' ),
		restoreReplace: document.getElementById( 'zoecloud-restore-replace' ),
		validateRestore: document.getElementById( 'zoecloud-validate-restore' ),
		runRestore: document.getElementById( 'zoecloud-run-restore' ),
		restoreFeedback: document.getElementById( 'zoecloud-restore-feedback' ),
	};

	if ( ! state.table || ! window.zoecloudAdmin ) {
		return;
	}

	const request = async ( path, options = {} ) => {
		const response = await fetch( zoecloudAdmin.root + path, {
			method: options.method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': zoecloudAdmin.nonce,
			},
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} );

		const data = await response.json().catch( () => ( {} ) );

		if ( ! response.ok ) {
			throw new Error( data.message || 'Request failed' );
		}

		return data;
	};

	const renderBackups = ( backups ) => {
		if ( ! backups.length ) {
			state.table.innerHTML = '<tr><td colspan="5">No backups yet.</td></tr>';
			if ( state.restoreFilename ) {
				state.restoreFilename.innerHTML = '<option value="">No backups available</option>';
			}
			return;
		}

		state.table.innerHTML = backups
			.map(
				( backup ) => `
					<tr>
						<td>${ backup.created_at || '' }</td>
						<td>${ backup.filename || '' }</td>
						<td>${ formatBytes( backup.size || 0 ) }</td>
						<td>${ backup.drive && backup.drive.file_id ? 'Uploaded' : backup.drive_error ? backup.drive_error : 'Pending' }</td>
						<td>
							<a class="button" href="${ backup.download_url || '#' }">Download</a>
							<button type="button" class="button zoecloud-select-restore" data-filename="${ backup.filename || '' }">Select</button>
						</td>
					</tr>
				`
			)
			.join( '' );

		if ( state.restoreFilename ) {
			const currentValue = state.restoreFilename.value;
			state.restoreFilename.innerHTML = backups
				.map( ( backup ) => `<option value="${ backup.filename || '' }">${ backup.filename || '' }</option>` )
				.join( '' );

			if ( currentValue ) {
				state.restoreFilename.value = currentValue;
			}
		}
	};

	const formatBytes = ( bytes ) => {
		if ( ! bytes ) {
			return '0 B';
		}

		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let value = Number( bytes );
		let unitIndex = 0;

		while ( value >= 1024 && unitIndex < units.length - 1 ) {
			value = value / 1024;
			unitIndex++;
		}

		return `${ value.toFixed( value >= 10 || unitIndex === 0 ? 0 : 1 ) } ${ units[ unitIndex ] }`;
	};

	const renderPreflight = ( preflight ) => {
		if ( ! state.preflight || ! preflight ) {
			return;
		}

		const rows = [
			[ 'ZipArchive', preflight.ziparchive ],
			[ 'Uploads writable', preflight.uploads_writable ],
			[ 'Can create backup files', preflight.can_create_files ],
			[ 'Free disk', preflight.disk_free_bytes === null ? 'Unknown' : formatBytes( preflight.disk_free_bytes ) ],
			[ 'Memory limit', preflight.memory_limit || 'Unknown' ],
			[ 'Max execution time', preflight.max_execution_time || 'Unknown' ],
		];

		state.preflight.innerHTML = rows
			.map( ( [ label, value ] ) => `<li class="${ value === false ? 'is-error' : '' }"><strong>${ label }:</strong> ${ value === true ? 'OK' : value === false ? 'Missing' : value }</li>` )
			.join( '' );

		if ( state.createButton ) {
			state.createButton.disabled = ! preflight.ready;
		}
	};

	const refresh = async () => {
		const status = await request( 'status' );
		state.statusText.textContent = status.drive.configured
			? `Drive configured for ${ status.drive.project_name || 'project' }.`
			: 'Drive not configured yet. Backups will be created locally.';
		if ( state.uploadDrive ) {
			state.uploadDrive.checked = !! status.drive.configured;
			state.uploadDrive.disabled = ! status.drive.configured;
		}
		renderPreflight( status.preflight );
		renderBackups( status.backups || [] );
	};

	const setFeedback = ( message, isError = false ) => {
		state.feedback.textContent = message;
		state.feedback.classList.toggle( 'is-error', isError );
	};

	state.createButton?.addEventListener( 'click', async () => {
		state.createButton.disabled = true;
		setFeedback( 'Creating backup…' );

		try {
			const result = await request( 'backups', {
				method: 'POST',
				body: {
					include_core: !! state.includeCore?.checked,
					upload_drive: !! state.uploadDrive?.checked,
				},
			} );
			setFeedback( result.drive_error ? `Backup created locally. Drive upload failed: ${ result.drive_error }` : 'Backup created successfully.' );
			await refresh();
		} catch ( error ) {
			setFeedback( error.message, true );
		} finally {
			state.createButton.disabled = false;
		}
	} );

	state.table.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.zoecloud-select-restore' );

		if ( ! button || ! state.restoreFilename ) {
			return;
		}

		state.restoreFilename.value = button.dataset.filename || '';
		setRestoreFeedback( `Selected ${ state.restoreFilename.value } for restore.` );
	} );

	const setRestoreFeedback = ( message, isError = false ) => {
		if ( ! state.restoreFeedback ) {
			return;
		}

		state.restoreFeedback.textContent = message;
		state.restoreFeedback.classList.toggle( 'is-error', isError );
	};

	state.validateRestore?.addEventListener( 'click', async () => {
		const filename = state.restoreFilename?.value || '';

		if ( ! filename ) {
			setRestoreFeedback( 'Select a backup first.', true );
			return;
		}

		try {
			const plan = await request( `restore?filename=${ encodeURIComponent( filename ) }` );
			setRestoreFeedback(
				`Valid backup. Origin: ${ plan.origin_home_url || 'unknown' }. Files: ${ plan.files_count ?? 'unknown' }. Database rows: ${ plan.database_rows ?? 'unknown' }.`
			);
		} catch ( error ) {
			setRestoreFeedback( error.message, true );
		}
	} );

	state.runRestore?.addEventListener( 'click', async () => {
		const filename = state.restoreFilename?.value || '';

		if ( ! filename ) {
			setRestoreFeedback( 'Select a backup first.', true );
			return;
		}

		if ( ! window.confirm( 'This will overwrite files and database tables. Continue?' ) ) {
			return;
		}

		state.runRestore.disabled = true;
		setRestoreFeedback( 'Running restore…' );

		try {
			await request( 'restore', {
				method: 'POST',
				body: {
					filename,
					search: state.restoreSearch?.value || '',
					replace: state.restoreReplace?.value || '',
					confirm: true,
				},
			} );
			setRestoreFeedback( 'Restore completed.' );
		} catch ( error ) {
			setRestoreFeedback( error.message, true );
		} finally {
			state.runRestore.disabled = false;
		}
	} );

	refresh().catch( ( error ) => setFeedback( error.message, true ) );
}() );
