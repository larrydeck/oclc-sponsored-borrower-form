<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Routing\Controller as BaseController;
use App\Forms\BorrowerForm;
use App\Mail\AccountCreated;
use App\Mail\ProffesorEmail;
use App\Mail\LibraryEmail;
use App\Mail\OclcError;
use App\Mail\GeneralError;
use App\Extlog;
use Kris\LaravelFormBuilder\FormBuilderTrait;
use App\Http\Requests\Borrower;
use App\Services\VerifyEmailService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Yaml;
use Mail;

if ($_ENV['APP_ENV'] ==='production') {
  putenv($_ENV['PROXY_HTTPS']);
  putenv($_ENV['PROXY_HTTP']);
}


class BorrowerController extends BaseController {
    use FormBuilderTrait;
    use ValidatesRequests;
    private $form_session = 'register_form';

    public function createStep1(Request $request)
    {

        $borrower = $request->session()->get('borrower');
        $branch_libraries = $this->get_branch_libraries();
        $borrowing_categories = $this->get_borrower_categories();

        // clear session data for borrower
        $request->session()->forget('borrower');
	$borrower = $this->get_prof_details($request->session()->get('saml2Auth'), $borrower);
        return view('borrower.create-step1')
            ->with(compact('borrower', $borrower))
            ->with(compact('branch_libraries', $branch_libraries))
            ->with(compact('borrowing_categories', $borrowing_categories))
        ;

    }

    public function get_prof_details($saml_attributes, $borrower) {
	$attrs = $saml_attributes->getSaml2User()->getAttributes();

	if (is_null($borrower)) {
        	$borrower = new \stdClass();
	}
        $borrower->prof_name = (isset($borrower->prof_name)) ? $borrower->prof_name :
				$attrs['http://schemas.microsoft.com/identity/claims/displayname'][0];
        $borrower->prof_email = (isset($borrower->prof_email)) ? $borrower->prof_email :
				$attrs['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'][0];
        $borrower->prof_dept = (isset($borrower->prof_dept)) ? $borrower->prof_dept :
				$attrs['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department'][0];
        $borrower->prof_telephone = (isset($borrower->prof_telephone)) ? $borrower->prof_telephone :
				$attrs['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/telephonenumber'][0];
	return $borrower;
    }
     /**
     * Post Request to store step1 info in session
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postCreateStep1(Borrower $request)
    {
       $validatedData = $request->validated();

        $borrower = $this->build_borrower($validatedData);
        $request->session()->put('borrower', $borrower);
        return redirect('/create-step2');
    }

    /**
     * Show the step 2 Form for creating a new product.
     *
     * @return \Illuminate\Http\Response
     */
    public function createStep2(Request $request)
    {
        $borrower = $request->session()->get('borrower');
        return view('borrower.create-step2')
          ->with(compact('borrower', $borrower));
    }

    public function created(Request $request)
    {
        $borrower = $request->session()->get('borrower');

        if (is_null($borrower)) {
            // clear session data
                $request->session()->flush();
                return redirect('/create-step1');
        }
        // clear session data
        $request->session()->flush();
        return view('borrower.success')
          ->with(compact('borrower', $borrower));
    }


    public function errorPage(Request $request)
    {
        $borrower = $request->session()->get('borrower');
        return view('borrower.error')
          ->with(compact('borrower', $borrower));
    }

    public function send_emails($request, $borrower) {
       $error_email = ENV('MAIL_ERROR_EMAIL_ADDRESS') ?? 'mutugi.gathuri@mcgill.ca';

       // Email the borrower
       if (!$this->verify_real_email($error_email, $borrower->borrower_email, $borrower)) {

            $error_msg = "The email address $borrower->borrower_email does not exist. Please check your spelling.";
            Mail::to($error_email)->send(new GeneralError($borrower, $error_msg));
            $request->session()->flash('message', $error_msg);
            return redirect('error')
                   ->with('error', $error_msg);
       }


       // Email the prof
        try {
            $result = Mail::to($borrower->prof_email)->send(new ProffesorEmail($borrower));
        } catch(\Swift_TransportException $e) {
            $response = $e->getMessage();
            Mail::to($error_email)->send(new GeneralError($borrower, $response));
            $request->session()->flash('message', $response);
            return redirect('error')
                   ->with('error', $response);
        }

        // Email the dept
        try {
            $result = Mail::to($borrower->branch_library_email)->send(new LibraryEmail($borrower));
        } catch(\Swift_TransportException $e) {
            $response = $e->getMessage();
            Mail::to($error_email)->send(new GeneralError($borrower, $response));
            $request->session()->flash('message', $response);
            return redirect('error')
                   ->with('error', $response);
        }
    }


    public function store(Request $request)
    {
        $error_email = ENV('MAIL_ERROR_EMAIL_ADDRESS') ?? 'mutugi.gathuri@mcgill.ca';

       $borrower = $request->session()->get('borrower');

       if($borrower->borrower_renewal == "Yes") {
            // Just send emails
           // Send the prof and the dept the emails
           $borrower->barcode = null;
           $this->send_emails($request, $borrower);

           return redirect()->route('borrower.created')
                ->with('success',
                    'Congratulations, your request has been received!');
       } else {
           // Create an account
           $borrower_created = $borrower->create();
       }

       if ($borrower_created) {
            // Send the prof and the dept the emails
            $this->send_emails($request, $borrower);

            return redirect()->route('borrower.created')
                    ->with('success',
                    'Congratulations, your request has been received!');
       } else {
            // Error occurred.
            $borrower->error_msg();

            // Send the email with the data
            Mail::to($error_email)->send(new OclcError($borrower));

            // clear session data
            $request->session()->flush();

            // Redirect to the form.
            return redirect('error')
            ->with('oclcerror', 'An Error has occured processing the request for the sponsored borrower.');
       }
    }

    private function build_borrower($request) {

        $borrower = new \stdClass();

        $borrower->data = $request;
        $borrower->branch_library_value  = $request['branch_library'];
        $borrower->branch_library_name = $this->get_branch_name($request['branch_library']);
        $borrower->branch_library_email = $this->get_branch_email($request['branch_library']);

        $borrower->prof_name = $request['prof_name'];
        $borrower->prof_telephone = $request['prof_telephone'];
        $borrower->prof_dept = $request['prof_dept'];
        $borrower->prof_email = $request['prof_email'];

        $borrower->borrower_cat  = $request['borrower_category'];
        $borrower->borrower_fname = $request['borrower_fname'];
        $borrower->borrower_lname = $request['borrower_lname'];
        $borrower->borrower_email = $request['borrower_email'];
        $borrower->borrower_address1 = $request['borrower_address1'];
        $borrower->borrower_address2 = $request['borrower_address2'] ?? null;
        $borrower->borrower_city = $request['borrower_city'];
        $borrower->borrower_postal_code = $request['borrower_postal_code'];
        $borrower->borrower_province_state = $request['borrower_province_state'];
        $borrower->borrower_startdate = $request['borrower_startdate'];
        $borrower->borrower_enddate = $request['borrower_enddate'] ?? null;
        $borrower->borrower_renewal = $request['borrower_renewal'] ?? "No";
        $borrower->borrower_telephone = $request['borrower_telephone'] ?? null;
        $borrower->borrower_terms = $request['borrower_terms'] ?? "No";

       if($borrower->borrower_renewal == "Yes") {
        $borrower->borrower_renewal_barcode = $request['borrower_renewal_barcode'] ?? null;
       }
        // Lets build the OCLC object
        return new \App\Oclc\Borrower((array)$borrower);
    }

    public function get_branch_libraries() {
      $branch_libraries = Yaml::parse(
        file_get_contents(base_path().'/branch_libraries.yml'));
      $keys = array_column($branch_libraries['branches'], 'label', 'key');
      return $keys;
    }

    public function get_borrower_categories() {
      $borrowers = Yaml::parse(
            file_get_contents(base_path().'/borrowing_categories.yml'));
      $keys = array_column($borrowers['categories'], 'label', 'key');
      return $keys;
    }


    public function get_branch_name($branch_value) {
     $data = Yaml::parse(file_get_contents(base_path().'/branch_libraries.yml'));
     $key = array_search($branch_value, array_column($data['branches'], 'key'));
     return $data['branches'][$key]['label'];
    }

    public function get_branch_email($branch_value) {
     $data = Yaml::parse(file_get_contents(base_path().'/branch_libraries.yml'));
     $key = array_search($branch_value, array_column($data['branches'], 'key'));
     return $data['branches'][$key]['email'];
    }

    public function verify_real_email($error_email, $test_email, $borrower) {

        $valid = true;
            // Initialize library class
        $mail = new VerifyEmailService();

        // Set the timeout value on stream
        $mail->setStreamTimeoutWait(20);

        // Set debug output mode
        $mail->Debug= TRUE;
        $mail->Debugoutput= 'html';

        // Set email address for SMTP request
        $mail->setEmailFrom($error_email);

        // Email to check
        // check the result of the mail before creating the account
        try{
            $result = Mail::to($test_email)->send(new AccountCreated($borrower));
        }catch(\Swift_TransportException $e){
            $response = $e->getMessage() ;
            $valid = false;
        }
        // Check if email is valid and exist
        return $valid;

    }
}
