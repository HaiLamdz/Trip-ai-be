<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination'        => ['required', 'string', 'max:255'],
            'origin'             => ['nullable', 'string', 'max:255'],
            'start_date'         => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'duration_days'      => ['required', 'integer', 'min:1', 'max:30'],
            'budget'             => ['required', 'numeric', 'min:0.01'],
            'num_people'         => ['required', 'integer', 'min:1', 'max:20'],
            'travel_type'        => ['nullable', 'string', 'in:solo,couple,family,group'],
            'transport_mode'     => ['nullable', 'string', 'max:100'],
            'accommodation_type' => ['nullable', 'string', 'in:hotel,homestay,hostel,resort,airbnb,villa,other'],
            'accommodation_area' => ['nullable', 'string', 'max:255'],
            'arrival_time'       => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/', 'bail'],
            'preferences'        => ['nullable', 'array'],
            'preferences.*'      => ['string', 'in:food,cafe,nature,culture,adventure,shopping,nightlife,budget,luxury'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination.required'       => 'Vui lòng nhập điểm đến.',
            'start_date.required'        => 'Vui lòng chọn ngày bắt đầu.',
            'start_date.date_format'     => 'Ngày bắt đầu phải theo định dạng YYYY-MM-DD.',
            'start_date.after_or_equal'  => 'Ngày bắt đầu phải từ hôm nay trở đi.',
            'duration_days.min'          => 'Số ngày phải từ 1 đến 30.',
            'duration_days.max'          => 'Số ngày phải từ 1 đến 30.',
            'budget.min'                 => 'Ngân sách phải là số dương.',
            'num_people.min'             => 'Số người phải từ 1 đến 20.',
            'num_people.max'             => 'Số người phải từ 1 đến 20.',
            'travel_type.in'             => 'Loại chuyến đi không hợp lệ.',
            'arrival_time.regex'         => 'Giờ đến phải theo định dạng HH:MM.',
        ];
    }
}
