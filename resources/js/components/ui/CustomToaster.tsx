import { Toaster } from "sonner";

export default function CustomToaster() {
    return (
        <Toaster
            position="top-right"
            theme="light"
            richColors
            closeButton
            visibleToasts={5}
            toastOptions={{
                className:
                    "text-lg p-5 min-w-[250px] min-h-[70px] md:min-w-[300px]",
            }}
        />
    );
}
