<?php

namespace App\Http\Controllers\System;

use App\Model\Investment;
use App\Model\Log;
use App\Model\Money;
use App\Model\Profile;
use App\Model\GoogleAuth;
use App\Model\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Storage;
use Excel;
use App\Jobs\SendMailJobs;

use Coinbase\Wallet\Client as Client_CB;
use Coinbase\Wallet\Configuration;
use Coinbase\Wallet\Resource\Address;
use Coinbase\Wallet\Resource\Account;
use Coinbase\Wallet\Enum\CurrencyCode;
use Coinbase\Wallet\Resource\Transaction;
use Coinbase\Wallet\Value\Money as CB_Money;

class AdminController extends Controller
{
	public static function coinbase(){
        $apiKey = 'BZwOpLqyp92A75oM';
        $apiSecret = '7UDOIAtJjobFYYonAcxD6YE7rSkqyHTa';

        $configuration = Configuration::apiKey($apiKey, $apiSecret);
        $client = Client::create($configuration);

        return $client;
    }
    public function getMemberListAdmin(Request $req)
    {
        $user = Session::get('user');
        if ($user->User_Level != 1 && $user->User_Level != 2 && $user->User_Level != 3) {
            dd('Stop');
        }
        $where = null;
        if ($req->UserID) {
            $where .= ' AND User_ID=' . $req->UserID;
        }
        if ($req->Username) {
            $where .= ' AND User_Name LIKE "' . $req->Username . '"';
        }
        if ($req->Email) {
            $where .= ' AND User_Email LIKE "' . $req->Email . '"';
        }
        if ($req->sponsor) {
            $where .= ' AND User_Parent = ' . $req->sponsor;
        }
        if ($req->level) {
            $where .= ' AND User_Agency_Level = ' . $req->level;
        }
        if ($req->datetime) {
            $where .= ' AND date(User_RegisteredDatetime) = "' . date('Y-m-d', strtotime($req->datetime)) . '"';
        }
        if ($req->status != null) {
            $where .= ' AND User_EmailActive = ' . $req->status;
        }
        if ($req->tree != '') {

            $where .= ' AND User_Tree LIKE "' . str_replace(', ', ',', $req->tree) . '%"';
        }
        if ($req->export == 1) {
            if ($user->User_Level != 1 && $user->User_Level != 2) {
                dd('Stop');
            }
            $Member = User::leftJoin('google2fa','google2fa.google2fa_User','users.User_ID')
                ->select('User_ID', 'google2fa.google2fa_User', 'User_Name', 'User_Email', 'User_RegisteredDatetime', 'User_Tree', 'User_Parent', 'User_EmailActive', 'User_Level')
                ->whereRaw('1 ' . $where)
                ->orderBy('User_RegisteredDatetime', 'DESC')->get();
            $member = array();
            foreach ($Member as $h) {
                if ($h->User_EmailActive == 1) {
                    $h->User_EmailActive = "Active";
                } else {
                    $h->User_EmailActive = "Not Active";
                }
                $member[] = $h;
            }
            //xuất excel
            $level = array(1 => 'Admin', 0 => 'Member', 2 => 'Finance', 3 => 'Support', 4 => 'Customer');
            // $listMemberExcel[] = array('ID','Email', 'ID Parent','Registred DateTime','Level','Status') ;
            $listMemberExcel[] = array('ID', 'Email', 'Registred DateTime', 'ID Parent', 'Tree', 'Level', 'Status', 'Auth');
            $i = 1;
            foreach ($member as $d) {
                $listMemberExcel[$i][0] = $d->User_ID;
                $listMemberExcel[$i][1] = $d->User_Email;
                $listMemberExcel[$i][2] = $d->User_RegisteredDatetime;
                $listMemberExcel[$i][3] = $d->User_Parent;
                $listMemberExcel[$i][4] = $d->User_Tree;
                $listMemberExcel[$i][5] = $level[$d->User_Level];
                $listMemberExcel[$i][6] = $d->User_EmailActive;
                if($d->google2fa_User){
                    $listMemberExcel[$i][7] = "Enable";
                }
                else{
                    $listMemberExcel[$i][7] = "Disable";
                }
                $i++;
            }

            Excel::create('Member', function ($excel) use ($listMemberExcel) {
                $excel->setTitle('Member');
                $excel->setCreator('Member')->setCompany('SMT');
                $excel->setDescription('Member');
                $excel->sheet('sheet1', function ($sheet) use ($listMemberExcel) {
                    $sheet->fromArray($listMemberExcel, null, 'A1', false, false);
                });
            })->download('xls');
        }
        $user_list = User::leftJoin('google2fa','google2fa.google2fa_User','users.User_ID')
        ->select('User_ID', 'google2fa.google2fa_User', 'User_Name', 'User_Email', 'User_RegisteredDatetime', 'User_Parent', 'User_EmailActive', 'User_Tree', 'User_Level', 'User_Agency_Level')
        ->whereRaw('1 ' . $where)
        ->orderBy('User_RegisteredDatetime', 'DESC');
        // dd($user_list->get());
        $user_list = $user_list->paginate(15);
        return view('System.Admin.User', compact('user_list'));
    }

    public function getDisableAuth($id){
        if(Session('user')->User_Level == 1){
            $check_auth = GoogleAuth::where('google2fa_User',$id)->delete();
            if($check_auth){
                $cmt_log = "Disable Auth ID User: " . $id;
                Log::insertLog(Session('user')->User_ID, "Disable Auth", 0, $cmt_log);
                return redirect()->back()->with(['flash_level' => 'success', 'flash_message' => 'Successfully Deleted Auth!']);
            }
            return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Auth Delete Failed!']);
        }
        return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Error!']);
    }

    public function getProfile(Request $request)
    {
        $profileList =  Profile::query();
        if ($request->Email) {
            $searchUserID = User::where('User_Email', $request->Email)->value('User_ID');
            $profileList = Profile::where('Profile_User', $searchUserID);
        }
        if ($request->UserID) {
            $profileList = $profileList->where('Profile_User', $request->UserID);
        }

        if ($request->status != null) {
            $profileList = $profileList->where('Profile_Status', $request->status);
        }
        if ($request->datefrom and $request->dateto) {
            $profileList = $profileList->whereRaw("DATE_FORMAT(Profile_Time, '%Y/%m/%d') >= '$request->datefrom' AND DATE_FORMAT(Profile_Time, '%Y/%m/%d') <= '$request->dateto' ");
        }
        if ($request->datefrom and !$request->dateto) {
            $profileList = $profileList->whereRaw("DATE_FORMAT(Profile_Time, '%Y/%m/%d') >= '$request->datefrom'");
        }
        if (!$request->datefrom and $request->dateto) {
            $profileList = $profileList->whereRaw("DATE_FORMAT(Profile_Time, '%Y/%m/%d') <= '$request->dateto'");
        }
        $profileList = $profileList->orderByDesc('Profile_ID')->paginate(15);
        // dd($profileList);
        return view('System.Admin.Confirm-Profile', compact('profileList'));
    }
    public function confirmProfile(Request $request)
    {
        if(Session('user')->User_Level != 1 && Session('user')->User_Level != 3){
            return response()->json(['status' => 'error', 'message' => 'Error, please contact admin!'], 200); 
        }
        if ($request->action == 1) {
            $updateProfileStatus = Profile::where('Profile_ID', $request->id)->update(['Profile_Status' => 1]);
            if ($updateProfileStatus) {
                $data = [];
                $user = Profile::join('users', 'Profile_User', 'User_ID')
                    ->where('Profile_ID', $request->id)
                    ->first();
                //Send mail job
                $data = array('User_ID' => $user->User_ID, 'User_Name' => $user->User_Name, 'User_Email' => $user->User_Email, 'token' => 'hihi');
                //Job

                dispatch(new SendMailJobs('KYC_SUCCESS', $data, 'KYC Notification!', $user->User_ID));

                return response()->json(['status' => 'success', 'message' => 'confirmed!'], 200);
            }
            return response()->json(['status' => 'error', 'message' => 'Error, please contact admin!'], 200);
        }
        if ($request->action == -1) {

            $removeKYC = Profile::join('users', 'Profile_User', 'User_ID')->where('Profile_ID', $request->id)->first();

            $deleteImage_Server = Storage::disk('ftp')->delete([$removeKYC->Profile_Passport_Image, $removeKYC->Profile_Passport_Image_Selfie]);

            if ($deleteImage_Server) {
                $data = [];
                $removeRecord = Profile::where('Profile_ID', $request->id)->delete();
                //Send mail job
                $data = array('User_ID' => $removeKYC->User_ID, 'User_Name' => $removeKYC->User_Name, 'User_Email' => $removeKYC->User_Email, 'token' => 'hihi');
                //Job
                dispatch(new SendMailJobs('KYC_ERROR', $data, 'KYC Notification!', $removeKYC->User_ID));

                return response()->json(['status' => 'success', 'message' => 'Disagreed!'], 200);
            }
            return response()->json(['status' => 'error', 'message' => 'Error, please contact admin!'], 200);
        }
    }

    public function getAdminInvestmentList(Request $request)
    {
        $investmentList = Investment::join('currency', 'investment_Currency', '=', 'currency.Currency_ID')
            ->join('package', 'package_ID', 'investment_Package')
            ->join('package_time', 'time_Month', 'investment_Package_Time')
            ->join('users', 'investment_User', 'User_ID')
            ->orderBy('investment_ID', 'DESC');

        if ($request->user_id) {
            $investmentList = $investmentList->where('investment_User', $request->user_id);
        }
        if ($request->email) {
            $searchUserID = User::where('User_Email', $request->email)->value('User_ID');
            $investmentList = $investmentList->where('investment_User', $searchUserID);
        }
        if ($request->status) {
            $investmentList = $investmentList->where('investment_Status', $request->status);
        }
        if ($request->datefrom and $request->dateto) {
            $investmentList = $investmentList->where('investment_Time', '>=', strtotime($request->datefrom))
                ->where('investment_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->datefrom and !$request->dateto) {
            $investmentList = $investmentList->where('investment_Time', '>=', strtotime($request->datefrom));
        }
        if (!$request->datefrom and $request->dateto) {
            $investmentList = $investmentList->where('investment_Time', '<', strtotime($request->dateto) + 86400);
        }
        $investmentList = $investmentList->paginate(15);
        return view('System.Admin.Investment', compact('investmentList'));
    }
    public function getWallet(Request $request)
    {
        dd(123);
        $level = array(1 => 'Admin', 0 => 'Member', 2 => 'Finance', 3 => 'Support', 4 => 'Customer', 5 => 'Bot');
        $walletList = Money::join('currency', 'Money_Currency', '=', 'currency.Currency_ID')
            ->join('moneyaction', 'Money_MoneyAction', '=', 'moneyaction.MoneyAction_ID')
            ->join('users', 'Money_User', 'users.User_ID')
            ->select('Money_ID', 'Money_User', 'users.User_Level', 'Money_MoneyAction', 'Money_USDT', 'Money_Currency', 'Money_USDTFee', 'Money_Time', 'currency.Currency_Name', 'Currency_Symbol', 'moneyaction.MoneyAction_Name', 'Money_Comment', 'Money_MoneyStatus', 'Money_Confirm', 'Money_Rate', 'Money_CurrentAmount');

        if ($request->id) {
            $walletList = $walletList->where('Money_ID', intval($request->id));
        }
        if ($request->user_id) {
            $walletList = $walletList->where('Money_User', $request->user_id);
        }
        if ($request->action) {
            $walletList = $walletList->where('Money_MoneyAction', $request->action);
        }
        if ($request->status) {
            //             $walletList = $walletList->where('Money_Confirm', $request->status);
            if ($request->status == 2) {
                $walletList = $walletList->where('Money_MoneyAction', 2)->where('Money_Confirm', 0);
            } else {
                $walletList = $walletList->where('Money_MoneyStatus', (int) $request->status);
            }
        }
        if ($request->datefrom and $request->dateto) {
            $walletList = $walletList->where('Money_Time', '>=', strtotime($request->datefrom))
                ->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->datefrom and !$request->dateto) {
            $walletList = $walletList->where('Money_Time', '>=', strtotime($request->datefrom));
        }
        if (!$request->datefrom and $request->dateto) {
            $walletList = $walletList->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->export) {

            Excel::create('History-Wallet' . date('YmdHis'), function ($excel) use ($walletList, $level) {
                $excel->sheet('report', function ($sheet) use ($walletList, $level) {
                    $sheet->appendRow(array(
                        'ID', 'User ID', 'User Level', 'Action', 'Comment', 'DateTime', 'Amount Coin', 'Currency', 'Rate', 'USD', 'Fee Coin', 'Fee USD', 'Status'
                    ));
                    $walletList->chunk(2000, function ($rows) use ($sheet, $level) {
                        foreach ($rows as $row) {
                            if ($row->Money_MoneyStatus == 1) {
                                if ($row->Money_MoneyAction == 2 && $row->Money_Confirm == 0) {
                                    $row->Money_Confirm = "Pending";
                                } else {
                                    $row->Money_Confirm = "Success";
                                }
                            } else {
                                $row->Money_Confirm = "Cancel";
                            }
                            $sheet->appendRow(array(

                                $row->Money_ID,
                                $row->Money_User,
                                $level[$row->User_Level],
                                $row->MoneyAction_Name,
                                $row->Money_Comment,
                                date('Y-m-d H:i:s', $row->Money_Time),
                                $row->Money_Currency == 8 ? $row->Money_USDT / $row->Money_Rate : $row->Money_USDT,
                                $row->Currency_Symbol,
                                $row->Money_Rate,
                                $row->Money_Currency == 8 ? $row->Money_USDT * $row->Money_Rate : $row->Money_USDT,
                                $row->Money_Currency == 8 ? $row->Money_USDTFee / $row->Money_Rate : $row->Money_USDTFee,
                                $row->Money_Currency == 8 ? $row->Money_USDTFee * $row->Money_Rate : $row->Money_USDTFee,
                                $row->Money_Confirm
                            ));
                        }
                    });
                });
            })->export('xlsx');
        }
        $walletList = $walletList->orderByDesc('Money_ID')->paginate(15);
        $action = DB::table('moneyaction')->get();
        return view('System.Admin.Wallet', compact('walletList', 'action'));
    }

    public function getInterest(Request $request)
    {
        $level = array(1 => 'Admin', 0 => 'Member', 2 => 'Finance', 3 => 'Support', 4 => 'Customer', 5 => 'Bot');
        $walletList = Money::where('Money_MoneyAction', 4)
            ->join('currency', 'Money_Currency', '=', 'currency.Currency_ID')
            ->join('moneyaction', 'Money_MoneyAction', '=', 'moneyaction.MoneyAction_ID')
            ->join('users', 'Money_User', 'users.User_ID')
            ->select('Money_ID', 'Money_User', 'users.User_Level', 'Money_MoneyAction', 'Money_USDT', 'Money_Currency', 'Money_USDTFee', 'Money_Time', 'currency.Currency_Name', 'Currency_Symbol', 'moneyaction.MoneyAction_Name', 'Money_Comment', 'Money_MoneyStatus', 'Money_Confirm', 'Money_Rate', 'Money_CurrentAmount');

        if ($request->id) {
            $walletList = $walletList->where('Money_ID', intval($request->id));
        }
        if ($request->user_id) {
            $walletList = $walletList->where('Money_User', $request->user_id);
        }
        if ($request->action) {
            $walletList = $walletList->where('Money_MoneyAction', $request->action);
        }
        if ($request->status) {
            //             $walletList = $walletList->where('Money_Confirm', $request->status);
            if ($request->status == 2) {
                $walletList = $walletList->where('Money_MoneyAction', 2)->where('Money_Confirm', 0);
            } else {
                $walletList = $walletList->where('Money_MoneyStatus', (int) $request->status);
            }
        }
        if ($request->datefrom and $request->dateto) {
            $walletList = $walletList->where('Money_Time', '>=', strtotime($request->datefrom))
                ->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->datefrom and !$request->dateto) {
            $walletList = $walletList->where('Money_Time', '>=', strtotime($request->datefrom));
        }
        if (!$request->datefrom and $request->dateto) {
            $walletList = $walletList->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->export) {

            Excel::create('History-Wallet' . date('YmdHis'), function ($excel) use ($walletList, $level) {
                $excel->sheet('report', function ($sheet) use ($walletList, $level) {
                    $sheet->appendRow(array(
                        'ID', 'User ID', 'User Level', 'Action', 'Comment', 'DateTime', 'Amount Coin', 'Currency', 'Rate', 'USD', 'Fee Coin', 'Fee USD', 'Status'
                    ));
                    $walletList->chunk(2000, function ($rows) use ($sheet, $level) {
                        foreach ($rows as $row) {
                            if ($row->Money_MoneyStatus == 1) {
                                if ($row->Money_MoneyAction == 2 && $row->Money_Confirm == 0) {
                                    $row->Money_Confirm = "Pending";
                                } else {
                                    $row->Money_Confirm = "Success";
                                }
                            } else {
                                $row->Money_Confirm = "Cancel";
                            }
                            $sheet->appendRow(array(

                                $row->Money_ID,
                                $row->Money_User,
                                $level[$row->User_Level],
                                $row->MoneyAction_Name,
                                $row->Money_Comment,
                                date('Y-m-d H:i:s', $row->Money_Time),
                                $row->Money_Currency == 8 ? $row->Money_USDT : $row->Money_USDT / $row->Money_Rate,
                                $row->Currency_Symbol,
                                $row->Money_Rate,
                                $row->Money_Currency != 8 ? $row->Money_USDT : $row->Money_USDT * $row->Money_Rate,
                                $row->Money_Currency != 8 ? $row->Money_USDTFee / $row->Money_Rate : $row->Money_USDTFee,
                                $row->Money_Currency == 8 ? $row->Money_USDTFee * $row->Money_Rate : $row->Money_USDTFee,
                                $row->Money_Confirm
                            ));
                        }
                    });
                });
            })->export('xlsx');
        }
        $walletList = $walletList->orderByDesc('Money_ID')->paginate(15);
        // dd($walletList);
        $action = DB::table('moneyaction')->get();
        return view('System.Admin.Interest', compact('walletList', 'action'));
    }

    public function getWithdraw(Request $request)
    {
        $withdrawCofirm = Money::join('users', 'Money_User', 'users.User_ID')
            ->where('Money_MoneyAction', 2)
            ->select('Money_ID', 'Money_User', 'Money_USDT', 'Money_Time', 'Money_Rate', 'Money_Confirm', 'users.User_Level');
        if ($request->email) {
            $searchuserID = User::where('User_Email', $request->email)->value('User_ID');
            $withdrawCofirm = $withdrawCofirm->where('Money_User', $searchuserID);
        }
        if ($request->id) {
            $withdrawCofirm = $withdrawCofirm->where('Money_ID', intval($request->id));
        }
        if ($request->user_id) {
            $withdrawCofirm = $withdrawCofirm->where('Money_User', $request->user_id);
        }
        if (isset($request->status)) {
            if ($request->status != 2) {
                $withdrawCofirm = $withdrawCofirm->where('Money_Confirm', $request->status);
            }
        }
        if ($request->datefrom and $request->dateto) {
            $withdrawCofirm = $withdrawCofirm->where('Money_Time', '>=', strtotime($request->datefrom))
                ->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->datefrom and !$request->dateto) {
            $withdrawCofirm = $withdrawCofirm->where('Money_Time', '>=', strtotime($request->datefrom));
        }
        if (!$request->datefrom and $request->dateto) {
            $withdrawCofirm = $withdrawCofirm->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        $withdrawCofirm = $withdrawCofirm->orderByDesc('Money_ID')->paginate(15);
        return view('System.Admin.Withdraw', compact('withdrawCofirm'));
    }


    protected function getHttp($url)
    {
        $client = new Client();
        $response = $client->get($url);
        return json_decode($response->getBody());
    }

    public function getProfit()
    {
        return view('System.Admin.Confirm-Profit');
    }
    //get Pay daily INterest
    public function getPayDailyInterest(Request $request)
    {
        $level = array(1 => 'Admin', 0 => 'User', 2 => 'Finance', 3 => 'Support', 4 => 'Customer', 5 => 'Bot');
        $profitCofirm = Money::join('users', 'Money_User', 'users.User_ID')
            ->where('Money_MoneyAction', 4)
            ->select('Money_ID', 'Money_User', 'Money_USDT', 'Money_Time', 'Money_Rate', 'Money_Confirm', 'users.User_Level', 'users.User_WalletGTC', 'Money_Confirm_Time');

        if ($request->email) {
            $searchuserID = User::where('User_Email', $request->email)->value('User_ID');
            $profitCofirm = $profitCofirm->where('Money_User', $searchuserID);
        }
        if ($request->id) {
            $profitCofirm = $profitCofirm->where('Money_ID', $request->id);
        }

        if ($request->wallet_status != null) {
            if ($request->wallet_status == 1) {
                $profitCofirm = $profitCofirm->where('users.User_WalletGTC', '!=', null);
            }
            if ($request->wallet_status == 0) {
                $profitCofirm = $profitCofirm->where('users.User_WalletGTC', null);
            }
        }
        if ($request->user_id) {
            $profitCofirm = $profitCofirm->where('Money_User', $request->user_id);
        }

        if (isset($request->status)) {
            $profitCofirm = $profitCofirm->where('Money_Confirm', $request->status);
        }

        if ($request->datefrom and $request->dateto) {
            $profitCofirm = $profitCofirm->where('Money_Time', '>=', strtotime($request->datefrom))
                ->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }
        if ($request->datefrom and !$request->dateto) {
            $profitCofirm = $profitCofirm->where('Money_Time', '>=', strtotime($request->datefrom));
        }
        if (!$request->datefrom and $request->dateto) {
            $profitCofirm = $profitCofirm->where('Money_Time', '<', strtotime($request->dateto) + 86400);
        }

        if ($request->export) {
            Excel::create('Admin-Pay-Interest-' . date('YmdHis'), function ($excel) use ($profitCofirm, $level) {
                $excel->sheet('report', function ($sheet) use ($profitCofirm, $level) {
                    $sheet->appendRow(array(
                        'Interest ID', 'Interest ID', 'User Level', 'Interest Amount', 'Money Rate', 'Interest Time', 'Confirm Time', 'Update Wallet', 'Status'
                    ));

                    $profitCofirm->chunk(2000, function ($rows) use ($sheet, $level) {
                        foreach ($rows as $row) {
                            if ($row->Money_Confirm == 1) {
                                $row->Money_Confirm = "Confirmed";
                            } elseif ($row->Money_Confirm == 0) {
                                $row->Money_Confirm = "Pending";
                            } else {
                                $row->Money_Confirm = "Cancel";
                            }
                            if ($row->User_WalletGTC) {
                                $row->User_WalletGTC = 'Updated';
                            } else {
                                $row->User_WalletGTC = 'None';
                            }
                            $sheet->appendRow(array(
                                $row->Money_ID, $row->Money_User, $level[$row->User_Level], $row->Money_USDT + 0, $row->Money_Rate, date('Y-m-d H:i:s', $row->Money_Time), $row->Money_Confirm_Time, $row->User_WalletGTC, $row->Money_Confirm
                            ));
                        }
                    });
                });
            })->download('xlsx');
        }
        $profitCofirm = $profitCofirm->orderByDesc('Money_ID')->paginate(25);
        $walletBalance = $this->getHttp("http://trustexc.com/api/get_balance");
        return view('System.Admin.Confirm-Profit', compact('profitCofirm', 'walletBalance'));
    }

    //Log Mail List
    public function getLogMail(Request $request)
    {
        $logMails = Log::join('users', 'Log_User', 'users.User_ID')
            ->select('User_Email', 'Log_User', 'Log_Comment', 'Log_CreatedAt', 'Log_User', 'Log_Action', 'Log_ID');
        if($request->UserID){
            $logMails = $logMails->where('Log_User',$request->UserID);
        }
        if($request->Email){
            $logMails = $logMails->where('User_Email',$request->Email);
        }
        if($request->Content){
            $logMails = $logMails->where('Log_Comment','like',"%$request->Content%");
        }
        $logMails = $logMails->orderByDesc('Log_CreatedAt')->paginate(15);
        return view('System.Admin.Log-Mail', compact('logMails'));
    }

    public function getWalletDetail($id)
    {
        if (Session('user')->User_Level != 1) {
            return redirect()->back();
        }
        $detail = Money::Join('currency', 'Money_Currency', 'Currency_ID')->Join('users', 'Money_User', 'User_ID')->join('moneyaction', 'MoneyAction_ID', 'Money_MoneyAction')->where('Money_ID', $id)->first();
        if (Input::get('confirm')) {
            if (Input::get('confirm') == 1) {
                if ($detail->Money_Confirm == 0) {
	                if($detail->Money_Currency == 8){
	                    if(!$detail->User_WalletAddress){
		                    return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Wallet SOX Not Found!']);
	                    }
	                    $transferSOX = app('App\Http\Controllers\Cron\CronController')->TransferToAddress('SXVXhGaNrGXuEmhzX2vVq3itzk2P9syCd2', $detail->Money_USDT, $detail->User_WalletAddress, 'Send Interest');
	                    if($transferSOX){
	                        $detail->Money_Confirm = 1;
	                        $detail->save();
	                        return redirect()->back()->with(['flash_level' => 'success', 'flash_message' => 'Send SOX Success!']);
	                    }else{
	                        return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Error! Please Check Log Send SOX']);
	                    }
	                }else if(($detail->Money_Currency == 1 || $detail->Money_Currency == 2)){
						// rút tiền ra khỏi coinbase
						$Currency = $detail->Money_Currency == 1 ? "BTC" : "ETH";
						$amountReal = abs($detail->Money_CurrentAmount);

						if($detail->Money_Currency == 2){
							$cb_account = 'ETH';
							$rate = $this->coinbase()->getSellPrice('ETH-USD')->getamount();
							$newMoney = new CB_Money($amountReal, CurrencyCode::ETH);
						}elseif($detail->Money_Currency == 1){
							return redirect()->back()->with(['flash_level'=>'error', 'flash_message'=>'Currency Error!']);
							$cb_account = 'BTC';
							$rate = $this->coinbase()->getSellPrice('BTC-USD')->getamount();
							$newMoney = new CB_Money($amountReal, CurrencyCode::BTC);
						}
						
						// Amount
						$transaction = Transaction::send([
							'toBitcoinAddress' => $detail->Money_Address,
							'amount'           => $newMoney,
							'description'      => $detail->Money_User.' Withdraw!'
						]);

						
						$account = $this->coinbase()->getAccount($cb_account);

						try {
							$a = $this->coinbase()->createAccountTransaction($account, $transaction);	
							
							Money::where('Money_ID',$id)->update(['Money_Confirm'=>1]);	
							//Money::where('Money_ID',$id)->update(['Money_MoneyStatus'=>1]);
	
				
							return redirect()->back()->with(['flash_level'=>'success', 'flash_message'=>'Confirm Successfully.']);
						}catch (\Exception $e) {
							return redirect()->back()->with(['flash_level'=>'error', 'flash_message'=>$e->getMessage()]);
						}	
					}
                }
            } else {
                if ($detail->Money_Confirm == 0) {
                    $detail->Money_Confirm = -1;
                    $detail->Money_MoneyStatus = -1;
                    $detail->save();
                }
            }
            return redirect()->route('system.admin.getWallet')->with(['flash_level' => 'success', 'flash_message' => 'Confirm withdraw success!']);
        }
        return view('System.Admin.WalletDetail', compact('detail'));
    }

    public function getStatistical()
    {
        $where = '';
        if (Input::get('from')) {
            $from = strtotime(date('Y-m-d', strtotime(Input::get('from'))));
            $where .= ' AND Money_Time >= ' . $from;
        }
        if (Input::get('to')) {
            $to = strtotime('+1 day', strtotime(date('Y-m-d', strtotime(Input::get('to')))));
            $where .= ' AND Money_Time < ' . $to;
        }
        $Statistic = Money::getStatistic($where);

        $Total = Money::StatisticTotal($where);
        // dd($Statistic->get(),$Total->get());
        if (Input::get('User_ID')) {
            $Statistic = $Statistic->where('Money_User', Input::get('User_ID'));
        }

        if (Input::get('User_Level') != null) {
            $Statistic = $Statistic->where('User_Level', Input::get('User_Level'));
        }

        $Statistic = $Statistic->paginate(15);
        $Total = $Total->get()[0];


        return view('System.Admin.Statistical', compact('Statistic', 'Total'));
    }

    public function postDepositAdmin(Request $req)
    {
        $user = User::find(session('user')->User_ID);
        if ($user->User_Level != 1) {
            dd('stop');
        }
        $rate = app('App\Http\Controllers\System\CoinbaseController')->coinRateBuy();
        // dd($rate);
        $arrCoin = [2 => 'ETH', 5 => 'USD', 8 => 'SOX'];
        $getInfo = User::where('User_ID', $req->user)->first();
        $amount = $req->amount;
        $coin = $req->coin;
        if (!$getInfo) {
            return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Error! User is not exist!']);
        }
        if (!$amount || $amount <= 0) {
            return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Error! Enter amount > 0!']);
        }
        $symbol = $arrCoin[$coin];
        $priceCoin = $rate[$symbol];
        //deposit
        $money = new Money();
        $money->Money_User = $getInfo->User_ID;
        $money->Money_USDT = $amount;
        $money->Money_Time = time();
        $money->Money_Comment = 'Deposit ' . ($symbol == 'USD' ? 'USDX' : $symbol);
        $money->Money_Currency = $coin;
        $money->Money_MoneyAction = 1;
        $money->Money_Address = '';
        $money->Money_CurrentAmount = $coin == 2 ? $amount / $priceCoin : $amount;
        $money->Money_Rate = $priceCoin;
        $money->Money_MoneyStatus = 1;
        $money->save();
        return redirect()->back()->with(['flash_level' => 'success', 'flash_message' => "Deposit $getInfo->User_ID $amount $symbol Success!"]);
    }

    public function getLoginByID($id)
    {
        $user = session('user');
        if ($user->User_Level == 1) {
            $userLogin = User::find($id);
            if ($userLogin) {
                $cmt_log = "Login ID User: " . $id;
                Log::insertLog(Session('user')->User_ID, "Login", 0, $cmt_log);
                Session::put('userTemp', $user);
                Session::put('user', $userLogin);
                return redirect()->route('Dashboard')->with(['flash_level' => 'success', 'flash_message' => 'Login Success']);
            }
        } else {
            return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'Error!']);
        }
    }

    public function getEditMailByID(Request $req){
        // dd($req->new_email);
        $check_id = User::where('User_ID',$req->id_user)->first();
        if($check_id){
            $check_mail = User::where('User_Email',$req->new_email)->first();
            if(!$check_mail){
                $cmt_log = "Change mail: ".$check_id->User_Email ." -> ". $req->new_email;
                Log::insertLog(Session('user')->User_ID, "Change Mail", 0, $cmt_log);
                $check_id->User_Email = $req->new_email;
                $check_id->save();
                return 1;
            }
            return 0;
        }
        return -1;
    }

    public function postCheckInterestList(Request $req){
        $user = Session('user');
	        return response()->json(['status'=>false, 'message'=>'Error!'], 200);
        if($user->User_Level != 1){
	        return response()->json(['status'=>false, 'message'=>'Error!']);
        }
        $arrIDMoney = $req->arr_check;
        $listID = implode(',', $arrIDMoney);
        if($req->type == 1){
        	$log = Log::insertLog($user->User_ID, 'Confirm List', 0, 'Confirm Interest List: '.$listID);
        	foreach($arrIDMoney as $id){
	        	$detail = Money::join('users', 'Money_User', 'User_ID')->where('Money_ID', $id)->first();
		        /*
if ($detail->Money_Confirm == 0) {
			        if(!$detail->User_WalletAddress){
			            continue;
			        }
			        $transferSOX = app('App\Http\Controllers\Cron\CronController')->TransferToAddress('SXVXhGaNrGXuEmhzX2vVq3itzk2P9syCd2', $detail->Money_USDT, $detail->User_WalletAddress, 'Send Interest');
			        if($transferSOX){
			            $detail->Money_Confirm = 1;
			            $detail->save();
			        }
			    }
*/
	        }
	        return response()->json(['status'=>true, 'message'=>'Send SOX List '.$listID.' Success!']);
	        
        }elseif($req->type == -1){
        	$log = Log::insertLog($user->User_ID, 'Cancel List', 0, 'Cancel Interest List: '.$listID);
			$getListUnConfirm = Money::whereIn('Money_ID', $arrIDMoney)->where('Money_Confirm', 0)->pluck('Money_ID')->toArray();
	        $updateList = Money::whereIn('Money_ID', $getListUnConfirm)->update(['Money_Confirm'=>-1]);
	        
	        return response()->json(['status'=>true, 'message'=>'Cancel List: '.$listID.' Success!']);
        }
        return response()->json(['status'=>false, 'message'=>'Action Error!']);
    }

    public function getLogSOX(){
        $log_SOX = DB::table('log_sox')->orderByDesc('Log_Sox_Time')->paginate(15);
        return view('System.Admin.Log-SOX', compact('log_SOX'));
    }
    
    public function getActiveMail($id){
        $check_user = User::where('User_ID',$id)->first();
        if(!$check_user){
            return redirect()->back()->with(['flash_level' => 'error', 'flash_message' => 'User ID is not exits!']);
        }
        $cmt_log = "Active Mail ID User: " . $id;
        Log::insertLog(Session('user')->User_ID, "Active Mail", 0, $cmt_log);
        $check_user->User_EmailActive = 1;
        $check_user->save();
        return redirect()->back()->with(['flash_level' => 'success', 'flash_message' => 'Active mail!']);
    }
}
