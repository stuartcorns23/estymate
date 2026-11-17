import { SVGAttributes, useId } from 'react';

// The Estymate "E" mark: three bars with a turquoise-to-violet gradient.
// Traced from the brand logo; drawn on a transparent background so it sits on any surface.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const gradientId = `estymate-mark-${useId().replace(/:/g, '')}`;

    return (
        <svg viewBox="0 -39.5 550 550" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Estymate" {...props}>
            <defs>
                <linearGradient id={gradientId} gradientUnits="userSpaceOnUse" x1="47" y1="47" x2="510.5" y2="510.5">
                    <stop offset="0" stopColor="#23F5C1" />
                    <stop offset="1" stopColor="#695AE1" />
                </linearGradient>
            </defs>
            <g fill={`url(#${gradientId})`}>
                <path d="M550 0V94H251Q211 94 186 125L160 158Q135 189 95 189H0V94H171Q211 94 236 63L264 31Q289 0 329 0Z" />
                <path d="M550 189V283H251Q211 283 186 314L160 347Q135 378 95 378H0V283H171Q211 283 236 252L264 220Q289 189 329 189Z" />
                <path d="M550 377V471H213L266 408Q291 377 331 377Z" />
            </g>
        </svg>
    );
}
