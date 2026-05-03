( function () {
	const state = {
		table: document.getElementById( 'zoecloud-backups-table' ),
		statusText: document.getElementById( 'zoecloud-status-text' ),
		feedback: document.getElementById( 'zoecloud-feedback' ),
		createButton: document.getElementById( 'zoecloud-create-backup' ),
		includeCore: document.getElementById( 'zoecloud-include-core' ),
		uploadDrive: document.getElementById( 'zoecloud-upload-drive' ),
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
			state.table.innerHTML = '<tr><td colspan="4">No backups yet.</td></tr>';
			return;
		}

		state.table.innerHTML = backups
			.map(
				( backup ) => `
					<tr>
						<td>${ backup.created_at || '' }</td>
						<td>${ backup.filename || '' }</td>
						<td>${ backup.drive && backup.drive.file_id ? 'Uploaded' : backup.drive_error ? backup.drive_error : 'Pending' }</td>
						<td><a class="button" href="${ backup.download_url || '#' }">Download</a></td>
					</tr>
				`
			)
			.join( '' );
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

	refresh().catch( ( error ) => setFeedback( error.message, true ) );
}() );
