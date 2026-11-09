import {
    siAkamai,
    siAlibabacloud,
    siCivo,
    siContabo,
    siDigitalocean,
    siExoscale,
    siGcore,
    siGooglecloud,
    siHetzner,
    siHostinger,
    siIonos,
    siNetcup,
    siOvh,
    siProxmox,
    siRaspberrypi,
    siScaleway,
    siUpcloud,
    siVultr,
} from 'simple-icons';
import type { SimpleIcon } from 'simple-icons';

/**
 * A hosting provider's logo, from its name as a machine reports it. AWS and
 * Azure aren't in simple-icons, so they (and "Other") show the
 * name alone.
 */
const brands: [RegExp, SimpleIcon][] = [
    [/hetzner/i, siHetzner],
    [/digital\s*ocean/i, siDigitalocean],
    [/linode|akamai/i, siAkamai],
    [/google/i, siGooglecloud],
    [/vultr/i, siVultr],
    [/ovh/i, siOvh],
    [/scaleway/i, siScaleway],
    [/hostinger/i, siHostinger],
    [/contabo/i, siContabo],
    [/upcloud/i, siUpcloud],
    [/ionos/i, siIonos],
    [/netcup/i, siNetcup],
    [/alibaba/i, siAlibabacloud],
    [/civo/i, siCivo],
    [/exoscale/i, siExoscale],
    [/gcore/i, siGcore],
    [/proxmox/i, siProxmox],
    [/raspberry/i, siRaspberrypi],
];

export function providerBrand(
    name: string | null | undefined,
): SimpleIcon | null {
    if (!name) return null;
    return brands.find(([pattern]) => pattern.test(name))?.[1] ?? null;
}
