import { __, sprintf } from '@wordpress/i18n';
import { Plus, X } from 'lucide-react';

/** Condition as the REST API returns it (roles and IPs come back as lists). */
export interface ApiCondition {
	type: ConditionType;
	op?: TextOp;
	name?: string;
	value: string | string[];
	negate?: boolean;
}

export type ConditionType = 'login' | 'role' | 'referrer' | 'agent' | 'ip' | 'cookie' | 'host' | 'header';
export type TextOp = 'equals' | 'contains' | 'regex';

/** Condition as edited in the form: every value is a plain string. */
export interface ConditionDraft {
	type: ConditionType;
	op: TextOp;
	name: string;
	value: string;
	negate: boolean;
}

export const MAX_CONDITIONS = 5;

const TYPE_LABELS: Record< ConditionType, string > = {
	login: __( 'Login state', 'ux-studio' ),
	role: __( 'User role', 'ux-studio' ),
	referrer: __( 'Referrer', 'ux-studio' ),
	agent: __( 'User agent', 'ux-studio' ),
	ip: __( 'IP address', 'ux-studio' ),
	cookie: __( 'Cookie', 'ux-studio' ),
	host: __( 'Domain (host)', 'ux-studio' ),
	header: __( 'HTTP header', 'ux-studio' ),
};

const OP_LABELS: Record< TextOp, string > = {
	contains: __( 'contains', 'ux-studio' ),
	equals: __( 'is exactly', 'ux-studio' ),
	regex: __( 'matches regex', 'ux-studio' ),
};

const DEFAULT_OP: Record< ConditionType, TextOp > = {
	login: 'equals',
	role: 'equals',
	referrer: 'contains',
	agent: 'contains',
	ip: 'equals',
	cookie: 'equals',
	host: 'equals',
	header: 'equals',
};

/** Types whose value is compared as text (operator choice). */
const TEXT_TYPES: ConditionType[] = [ 'referrer', 'agent', 'host', 'cookie', 'header' ];

export function emptyCondition( type: ConditionType = 'login' ): ConditionDraft {
	return { type, op: DEFAULT_OP[ type ], name: '', value: type === 'login' ? 'in' : '', negate: false };
}

export function fromApi( c: ApiCondition ): ConditionDraft {
	return {
		type: c.type,
		op: c.op ?? DEFAULT_OP[ c.type ],
		name: c.name ?? '',
		value: Array.isArray( c.value ) ? c.value.join( ', ' ) : c.value,
		negate: Boolean( c.negate ),
	};
}

/** Whether the row is filled in enough to be sent (an empty cookie / header value is allowed: "must exist"). */
function isComplete( c: ConditionDraft ): boolean {
	if ( c.type === 'cookie' || c.type === 'header' ) {
		return c.name.trim() !== '';
	}
	return c.value.trim() !== '';
}

export function toPayload( drafts: ConditionDraft[] ): Record< string, unknown >[] {
	return drafts.filter( isComplete ).map( ( c ) => {
		const out: Record< string, unknown > = { type: c.type, value: c.value.trim(), negate: c.negate };
		if ( TEXT_TYPES.includes( c.type ) ) {
			out.op = c.op;
		}
		if ( c.type === 'cookie' || c.type === 'header' ) {
			out.name = c.name.trim();
		}
		return out;
	} );
}

/* Literal strings, so that the translation tools can find them. */
const TEXT_SUMMARY: Record< 'referrer' | 'agent' | 'host', Record< TextOp, string > > = {
	referrer: {
		contains: __( 'referrer contains "%s"', 'ux-studio' ),
		equals: __( 'referrer is "%s"', 'ux-studio' ),
		regex: __( 'referrer matches "%s"', 'ux-studio' ),
	},
	agent: {
		contains: __( 'browser contains "%s"', 'ux-studio' ),
		equals: __( 'browser is "%s"', 'ux-studio' ),
		regex: __( 'browser matches "%s"', 'ux-studio' ),
	},
	host: {
		contains: __( 'domain contains "%s"', 'ux-studio' ),
		equals: __( 'domain is "%s"', 'ux-studio' ),
		regex: __( 'domain matches "%s"', 'ux-studio' ),
	},
};

const NAMED_SUMMARY: Record< 'cookie' | 'header', { exists: string; ops: Record< TextOp, string > } > = {
	cookie: {
		exists: __( 'cookie %s is set', 'ux-studio' ),
		ops: {
			contains: __( 'cookie %1$s contains "%2$s"', 'ux-studio' ),
			equals: __( 'cookie %1$s is "%2$s"', 'ux-studio' ),
			regex: __( 'cookie %1$s matches "%2$s"', 'ux-studio' ),
		},
	},
	header: {
		exists: __( 'header %s is sent', 'ux-studio' ),
		ops: {
			contains: __( 'header %1$s contains "%2$s"', 'ux-studio' ),
			equals: __( 'header %1$s is "%2$s"', 'ux-studio' ),
			regex: __( 'header %1$s matches "%2$s"', 'ux-studio' ),
		},
	},
};

/** One condition in words, e.g. `referrer contains "google"`. */
export function describeCondition( c: ApiCondition ): string {
	const value = Array.isArray( c.value ) ? c.value.join( ', ' ) : c.value;
	const op: TextOp = c.op ?? DEFAULT_OP[ c.type ];
	let text: string;
	switch ( c.type ) {
		case 'login':
			text = value === 'in' ? __( 'logged in', 'ux-studio' ) : __( 'logged out', 'ux-studio' );
			break;
		case 'role':
			/* translators: %s: role names */
			text = sprintf( __( 'role %s', 'ux-studio' ), value );
			break;
		case 'ip':
			/* translators: %s: IP addresses or ranges */
			text = sprintf( __( 'IP %s', 'ux-studio' ), value );
			break;
		case 'cookie':
		case 'header':
			text =
				value === ''
					? sprintf( NAMED_SUMMARY[ c.type ].exists, c.name ?? '' )
					: sprintf( NAMED_SUMMARY[ c.type ].ops[ op ], c.name ?? '', value );
			break;
		default:
			text = sprintf( TEXT_SUMMARY[ c.type ][ op ], value );
	}
	/* translators: %s: a condition in words, negated */
	return c.negate ? sprintf( __( 'not: %s', 'ux-studio' ), text ) : text;
}

export function describeConditions( conditions: ApiCondition[] ): string {
	return conditions.map( describeCondition ).join( '; ' );
}

/* ------------------------------------------------------------------ */

export function ConditionsEditor( {
	conditions,
	onChange,
}: {
	conditions: ConditionDraft[];
	onChange: ( next: ConditionDraft[] ) => void;
} ): JSX.Element {
	const update = ( index: number, patch: Partial< ConditionDraft > ): void =>
		onChange( conditions.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) ) );

	return (
		<div className="uxs-rd-cond">
			<div className="uxs-rd-cond__head">
				<strong>{ __( 'Conditions (optional)', 'ux-studio' ) }</strong>
				<span className="uxs-rd-cond__help">
					{ __( 'The redirect applies only to visitors who meet all conditions. A redirect without conditions is the fallback and is tried last. Several redirects can share one old URL.', 'ux-studio' ) }
				</span>
			</div>
			{ conditions.map( ( c, index ) => {
				const textual = TEXT_TYPES.includes( c.type );
				const named = c.type === 'cookie' || c.type === 'header';
				return (
					<div className="uxs-rd-cond__row" key={ index }>
						<select
							aria-label={ __( 'Condition type', 'ux-studio' ) }
							value={ c.type }
							onChange={ ( e ) => {
								const type = e.target.value as ConditionType;
								update( index, { type, op: DEFAULT_OP[ type ], value: type === 'login' ? 'in' : '', name: '' } );
							} }
						>
							{ ( Object.keys( TYPE_LABELS ) as ConditionType[] ).map( ( t ) => (
								<option key={ t } value={ t }>
									{ TYPE_LABELS[ t ] }
								</option>
							) ) }
						</select>
						{ named && (
							<input
								type="text"
								aria-label={ c.type === 'cookie' ? __( 'Cookie name', 'ux-studio' ) : __( 'Header name', 'ux-studio' ) }
								placeholder={ c.type === 'cookie' ? __( 'cookie name', 'ux-studio' ) : 'X-Header-Name' }
								value={ c.name }
								onChange={ ( e ) => update( index, { name: e.target.value } ) }
							/>
						) }
						{ textual && (
							<select aria-label={ __( 'Comparison', 'ux-studio' ) } value={ c.op } onChange={ ( e ) => update( index, { op: e.target.value as TextOp } ) }>
								{ ( Object.keys( OP_LABELS ) as TextOp[] ).map( ( o ) => (
									<option key={ o } value={ o }>
										{ OP_LABELS[ o ] }
									</option>
								) ) }
							</select>
						) }
						{ c.type === 'login' ? (
							<select aria-label={ __( 'Login state', 'ux-studio' ) } value={ c.value } onChange={ ( e ) => update( index, { value: e.target.value } ) }>
								<option value="in">{ __( 'Logged in', 'ux-studio' ) }</option>
								<option value="out">{ __( 'Logged out', 'ux-studio' ) }</option>
							</select>
						) : (
							<input
								type="text"
								className="uxs-rd-cond__value"
								aria-label={ __( 'Value', 'ux-studio' ) }
								placeholder={
									c.type === 'role'
										? 'administrator, editor'
										: c.type === 'ip'
										? '203.0.113.5, 10.0.0.0/8'
										: named
										? __( 'value (empty = only has to exist)', 'ux-studio' )
										: c.type === 'host'
										? 'old-domain.com'
										: ''
								}
								value={ c.value }
								onChange={ ( e ) => update( index, { value: e.target.value } ) }
							/>
						) }
						{ c.type !== 'login' && (
							<label className="uxs-rd-cond__negate">
								<input type="checkbox" checked={ c.negate } onChange={ ( e ) => update( index, { negate: e.target.checked } ) } /> { __( 'Invert', 'ux-studio' ) }
							</label>
						) }
						<button type="button" className="button button-small" aria-label={ __( 'Remove condition', 'ux-studio' ) } onClick={ () => onChange( conditions.filter( ( _, i ) => i !== index ) ) }>
							<X size={ 14 } />
						</button>
					</div>
				);
			} ) }
			{ conditions.length < MAX_CONDITIONS && (
				<div>
					<button type="button" className="button button-small" onClick={ () => onChange( [ ...conditions, emptyCondition() ] ) }>
						<Plus size={ 14 } /> { __( 'Add condition', 'ux-studio' ) }
					</button>
				</div>
			) }
		</div>
	);
}
