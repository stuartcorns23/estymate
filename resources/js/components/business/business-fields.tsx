import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface BusinessForm {
    name: string;
    email: string;
    phone: string;
    address_line_1: string;
    address_line_2: string;
    city: string;
    county: string;
    postcode: string;
    vat_number: string;
    company_number: string;
    [key: string]: string;
}

interface Field {
    name: keyof BusinessForm & string;
    label: string;
    placeholder?: string;
    type?: string;
    autoComplete?: string;
}

const detailFields: Field[] = [
    { name: 'name', label: 'Business name', placeholder: 'Acme Ltd', autoComplete: 'organization' },
    { name: 'email', label: 'Billing email', type: 'email', placeholder: 'accounts@example.co.uk', autoComplete: 'email' },
    { name: 'phone', label: 'Phone', type: 'tel', autoComplete: 'tel' },
    { name: 'vat_number', label: 'VAT number', placeholder: 'GB123456789' },
    { name: 'company_number', label: 'Company number', placeholder: '01234567' },
];

const addressFields: Field[] = [
    { name: 'address_line_1', label: 'Address line 1', autoComplete: 'address-line1' },
    { name: 'address_line_2', label: 'Address line 2', autoComplete: 'address-line2' },
    { name: 'city', label: 'Town or city', autoComplete: 'address-level2' },
    { name: 'county', label: 'County', autoComplete: 'address-level1' },
    { name: 'postcode', label: 'Postcode', autoComplete: 'postal-code' },
];

export const emptyBusinessForm: BusinessForm = {
    name: '',
    email: '',
    phone: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    county: '',
    postcode: '',
    vat_number: '',
    company_number: '',
};

interface BusinessFieldsProps {
    data: BusinessForm;
    setData: (key: keyof BusinessForm, value: string) => void;
    errors: Partial<Record<keyof BusinessForm, string>>;
    disabled?: boolean;
    withAddress?: boolean;
}

export default function BusinessFields({ data, setData, errors, disabled, withAddress = true }: BusinessFieldsProps) {
    const fields = withAddress ? [...detailFields, ...addressFields] : detailFields;

    return (
        <>
            {fields.map((field) => (
                <div key={field.name} className="grid gap-2">
                    <Label htmlFor={field.name}>
                        {field.label}
                        {field.name !== 'name' && <span className="text-muted-foreground font-normal"> (optional)</span>}
                    </Label>
                    <Input
                        id={field.name}
                        type={field.type ?? 'text'}
                        required={field.name === 'name'}
                        value={data[field.name]}
                        onChange={(e) => setData(field.name, e.target.value)}
                        disabled={disabled}
                        placeholder={field.placeholder}
                        autoComplete={field.autoComplete}
                    />
                    <InputError message={errors[field.name]} />
                </div>
            ))}
        </>
    );
}
