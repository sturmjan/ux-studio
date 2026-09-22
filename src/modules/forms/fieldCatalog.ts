/**
 * Palette metadata for every field type: category grouping, label and icon.
 * Purely a UI concern (server-side whitelist lives in Fields.php) - drives
 * the left palette panel and the field-type badge in the canvas.
 */
import { __ } from '@wordpress/i18n';
import {
	AlignLeft,
	Calendar,
	Check,
	CheckSquare,
	ChevronDownSquare,
	Clock,
	Code2,
	FileText,
	Hash,
	Layers,
	Link as LinkIcon,
	List,
	Lock,
	Mail,
	Milestone,
	Phone,
	Shield,
	SquareStack,
	Type as TypeIcon,
	Upload,
	type LucideIcon,
} from 'lucide-react';
import type { FieldType } from './types';

export interface FieldCatalogEntry {
	type: FieldType;
	label: string;
	icon: LucideIcon;
	category: 'basic' | 'choice' | 'advanced' | 'layout';
}

export function fieldCatalog(): FieldCatalogEntry[] {
	return [
		{ type: 'text', label: __( 'Text', 'ux-studio' ), icon: TypeIcon, category: 'basic' },
		{ type: 'textarea', label: __( 'Textarea', 'ux-studio' ), icon: AlignLeft, category: 'basic' },
		{ type: 'email', label: __( 'Email', 'ux-studio' ), icon: Mail, category: 'basic' },
		{ type: 'url', label: __( 'URL', 'ux-studio' ), icon: LinkIcon, category: 'basic' },
		{ type: 'tel', label: __( 'Phone', 'ux-studio' ), icon: Phone, category: 'basic' },
		{ type: 'number', label: __( 'Number', 'ux-studio' ), icon: Hash, category: 'basic' },
		{ type: 'password', label: __( 'Password', 'ux-studio' ), icon: Lock, category: 'basic' },
		{ type: 'hidden', label: __( 'Hidden', 'ux-studio' ), icon: Shield, category: 'basic' },
		{ type: 'date', label: __( 'Date', 'ux-studio' ), icon: Calendar, category: 'basic' },
		{ type: 'time', label: __( 'Time', 'ux-studio' ), icon: Clock, category: 'basic' },

		{ type: 'select', label: __( 'Select', 'ux-studio' ), icon: ChevronDownSquare, category: 'choice' },
		{ type: 'radio', label: __( 'Radio buttons', 'ux-studio' ), icon: List, category: 'choice' },
		{ type: 'checkbox', label: __( 'Checkbox', 'ux-studio' ), icon: Check, category: 'choice' },
		{ type: 'checkbox_group', label: __( 'Checkbox group', 'ux-studio' ), icon: CheckSquare, category: 'choice' },
		{ type: 'multiselect', label: __( 'Multi-select', 'ux-studio' ), icon: SquareStack, category: 'choice' },
		{ type: 'acceptance', label: __( 'Acceptance (GDPR)', 'ux-studio' ), icon: Shield, category: 'choice' },

		{ type: 'file', label: __( 'File upload', 'ux-studio' ), icon: Upload, category: 'advanced' },
		{ type: 'captcha', label: __( 'CAPTCHA', 'ux-studio' ), icon: Shield, category: 'advanced' },

		{ type: 'html', label: __( 'HTML content', 'ux-studio' ), icon: Code2, category: 'layout' },
		{ type: 'step', label: __( 'Step break', 'ux-studio' ), icon: Milestone, category: 'layout' },
	];
}

export function fieldCatalogEntry( type: FieldType ): FieldCatalogEntry {
	const entry = fieldCatalog().find( ( f ) => f.type === type );
	return entry ?? { type, label: type, icon: FileText, category: 'basic' };
}

export const CATEGORY_LABELS: Record< FieldCatalogEntry[ 'category' ], string > = {
	basic: __( 'Basic', 'ux-studio' ),
	choice: __( 'Choices', 'ux-studio' ),
	advanced: __( 'Advanced', 'ux-studio' ),
	layout: __( 'Layout', 'ux-studio' ),
};
