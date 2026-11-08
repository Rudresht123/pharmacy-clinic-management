import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { OrderStatus } from '../orders/types';

export type DashboardRange = '7d' | '30d' | '3m' | '1y';

export interface Kpi {
    value: number;
    previous: number;
    change: number | null;
    series: number[];
}

export interface Slice {
    key: string;
    label: string;
    value: number;
}

export interface LabDashboard {
    range: DashboardRange;
    from: string;
    to: string;
    kpis: {
        ordered: Kpi;
        completed: Kpi;
        pending: { value: number; orders: number };
        cancelled: Kpi;
    };
    overview: {
        points: { key: string; label: string; title: string }[];
        ordered: number[];
        completed: number[];
    };
    departments: Slice[];
    top_tests: Slice[];
    recent: {
        id: number;
        order_number: string;
        patient_name: string | null;
        patient_code: string | null;
        tests: string;
        department: string | null;
        ordered_at: string | null;
        status: OrderStatus;
    }[];
}

export function useLabDashboard(range: DashboardRange) {
    return useQuery({
        queryKey: ['tenant/lab', 'dashboard', range],
        queryFn: async (): Promise<LabDashboard> => {
            const { data } = await http.get<ApiResponse<LabDashboard>>('/tenant/lab/dashboard', { params: { range } });

            return data.data;
        },
        placeholderData: (previous) => previous,
        staleTime: 60 * 1000,
    });
}
