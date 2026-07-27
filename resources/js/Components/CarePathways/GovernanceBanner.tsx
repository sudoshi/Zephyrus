import { ShieldAlert } from "lucide-react";
import { Surface } from "@/Components/ui/Surface";

interface GovernanceBannerProps {
    warning: string;
    state: string;
    signoffCount: number;
}

// Mandatory framing for every catalog surface: the release is inactive and
// nothing here is clinically approved. Never soften or hide this banner.
export function GovernanceBanner({
    warning,
    state,
    signoffCount,
}: GovernanceBannerProps) {
    return (
        <Surface className="p-4">
            <div className="flex items-start gap-3">
                <ShieldAlert
                    className="mt-0.5 h-5 w-5 shrink-0 text-healthcare-warning dark:text-healthcare-warning-dark"
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        Reference catalog — not clinically approved
                    </p>
                    <p className="mt-1 text-xs leading-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {warning}
                    </p>
                    <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        <div className="flex gap-1.5">
                            <dt className="font-medium">Release state:</dt>
                            <dd className="font-semibold uppercase tracking-wide text-healthcare-warning dark:text-healthcare-warning-dark">
                                {state}
                            </dd>
                        </div>
                        <div className="flex gap-1.5">
                            <dt className="font-medium">Clinical sign-offs:</dt>
                            <dd className="font-semibold tabular-nums">
                                {signoffCount}
                            </dd>
                        </div>
                        <div className="flex gap-1.5">
                            <dt className="font-medium">
                                Patient / Eddy serving:
                            </dt>
                            <dd className="font-semibold">Off</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </Surface>
    );
}
