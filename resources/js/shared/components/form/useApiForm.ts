import { useForm, type FieldValues, type Path, type UseFormProps } from 'react-hook-form';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';

/**
 * react-hook-form with Laravel's 422 responses wired in.
 *
 * `submit` runs the request and, when the API rejects with validation
 * errors, drops each message onto its own field. Anything else surfaces on
 * a synthetic `root` error. Forms therefore never parse error payloads.
 */
export function useApiForm<TValues extends FieldValues>(options?: UseFormProps<TValues>) {
    const form = useForm<TValues>(options);

    async function submit<TResult>(
        values: TValues,
        action: (values: TValues) => Promise<TResult>,
    ): Promise<TResult | undefined> {
        form.clearErrors();

        try {
            return await action(values);
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                Object.entries(validation).forEach(([field, messages]) => {
                    form.setError(field as Path<TValues>, {
                        type: 'server',
                        message: messages[0],
                    });
                });

                // Focus the first field the server rejected.
                const first = Object.keys(validation)[0];

                if (first) {
                    form.setFocus(first as Path<TValues>);
                }
            } else {
                form.setError('root', {
                    type: 'server',
                    message: resolveErrorMessage(error),
                });
            }

            return undefined;
        }
    }

    return { ...form, submit };
}
