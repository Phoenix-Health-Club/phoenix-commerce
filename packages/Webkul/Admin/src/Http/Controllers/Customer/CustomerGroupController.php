<?php

namespace Webkul\Admin\Http\Controllers\Customer;

use Illuminate\Support\Facades\Event;
use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Admin\DataGrids\CustomerGroupDataGrid;
use Webkul\Core\Rules\Code;

class CustomerGroupController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Customer\Repositories\CustomerGroupRepository  $customerGroupRepository;
     * @return void
     */
    public function __construct(protected CustomerGroupRepository $customerGroupRepository)
    {
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(CustomerGroupDataGrid::class)->toJson();
        }

        return view('admin::customers.groups.index');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Resources\Json\JsonResource;
     */
    public function store()
    {
        $this->validate(request(), [
            'code' => ['required', 'unique:customer_groups,code', new Code],
            'name' => 'required',
        ]);

        Event::dispatch('customer.customer_group.create.before');

        $data = array_merge(request()->only([
            'code',
            'name'
        ]), [
            'is_user_defined' => 1,
        ]);

        $customerGroup = $this->customerGroupRepository->create($data);

        Event::dispatch('customer.customer_group.create.after', $customerGroup);

        return new JsonResource([
            'message' => trans('admin::app.customers.groups.index.create.create-success'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\View\View
     */
    public function edit($id)
    {
        $group = $this->customerGroupRepository->findOrFail($id);

        return view('admin::customers.groups.edit', compact('group'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        $this->validate(request(), [
            'code' => ['required', 'unique:customer_groups,code,' . $id, new Code],
            'name' => 'required',
        ]);

        Event::dispatch('customer.customer_group.update.before', $id);

        $data = request()->only([
            'code',
            'name'
        ]);

        $customerGroup = $this->customerGroupRepository->update($data, $id);

        Event::dispatch('customer.customer_group.update.after', $customerGroup);

        session()->flash('success', trans('admin::app.customers.groups.edit.update-success'));

        return redirect()->route('admin.groups.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $customerGroup = $this->customerGroupRepository->findOrFail($id);

        if (! $customerGroup->is_user_defined) {
            return response()->json([
                'message' => trans('admin::app.customers.customers.group-default'),
            ], 400);
        }

        if ($customerGroup->customers->count()) {
            return response()->json([
                'message' => trans('admin::app.customers.groups.customer-associate'),
            ], 400);
        }

        try {
            Event::dispatch('customer.customer_group.delete.before', $id);

            $this->customerGroupRepository->delete($id);

            Event::dispatch('customer.customer_group.delete.after', $id);

            return response()->json(['message' => trans('admin::app.customers.groups.delete-success')]);
        } catch (\Exception $e) {
        }

        return response()->json(['message' => trans('admin::app.customers.groups.delete-failed')], 500);
    }
}
